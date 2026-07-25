<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row-Level Security sur toutes les tables tenant-scopées + vues métier + rôle applicatif.
 *
 * L'application se connecte avec le rôle silaris_app (jamais propriétaire des tables,
 * sinon RLS bypassée). Le middleware/bootstrap job exécute :
 *   SET LOCAL app.tenant_id = '<uuid>'
 * Toute requête sans contexte tenant échoue (current_setting lève une erreur).
 */
return new class extends Migration
{
    /** Tables tenant-scopées soumises à RLS. */
    private const TENANT_TABLES = [
        'companies', 'branches', 'tenant_settings',
        'users', 'roles', 'api_keys', 'sessions_log',
        'workflow_definitions', 'sequences',
        'parties', 'party_contacts', 'party_addresses', 'opportunities', 'complaints', 'portal_accounts',
        'tariffs', 'quotes',
        'shipments', 'shipment_events', 'shipment_tasks', 'shipment_comments', 'transport_segments', 'cargo_items',
        'bookings', 'containers', 'container_assignments', 'bills_of_lading', 'consolidations',
        'air_waybills',
        'trucks', 'trailers', 'drivers', 'missions', 'proof_of_deliveries',
        'tracking_subscriptions', 'tracking_events',
        'documents', 'document_versions', 'document_downloads',
        'tax_rates', 'invoices',
        'notification_templates', 'notification_preferences', 'notifications', 'notification_deliveries',
        'odoo_connections', 'odoo_entity_maps', 'odoo_sync_logs',
        'carrier_api_credentials', 'carrier_exchange_logs',
        'webhook_endpoints', 'webhook_deliveries',
        'audit_logs', 'outbox_events', 'saved_views', 'dashboard_widgets',
    ];

    public function up(): void
    {
        // Rôle applicatif — idempotent ; nécessite CREATEROLE sur l'utilisateur de migration.
        DB::unprepared(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'silaris_app') THEN
        CREATE ROLE silaris_app NOLOGIN;
    END IF;
END
$$;
GRANT USAGE ON SCHEMA public TO silaris_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO silaris_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO silaris_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO silaris_app;

-- Audit : append-only au niveau moteur.
REVOKE UPDATE, DELETE ON audit_logs FROM silaris_app;
SQL);

        foreach (self::TENANT_TABLES as $tableName) {
            DB::unprepared(sprintf(<<<'SQL'
ALTER TABLE %1$s ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON %1$s
    USING (tenant_id = current_setting('app.tenant_id')::uuid)
    WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid);
SQL, $tableName));
        }

        // ——— Vues métier ———
        DB::unprepared(<<<'SQL'
CREATE VIEW v_shipments_list AS
SELECT s.id, s.tenant_id, s.reference, s.direction, s.mode, s.status, s.priority,
       s.origin_locode, s.destination_locode, s.etd, s.eta, s.atd, s.ata, s.closed_at,
       p.name  AS client_name, p.id AS client_id,
       b.code  AS branch_code,
       u.first_name || ' ' || u.last_name AS agent_name, s.agent_id,
       (SELECT count(*) FROM documents d
         WHERE d.shipment_id = s.id AND d.status = 'missing' AND d.deleted_at IS NULL) AS missing_documents,
       (SELECT count(*) FROM container_assignments ca
         WHERE ca.shipment_id = s.id AND ca.returned_at IS NULL) AS active_containers,
       (SELECT count(*) FROM shipment_tasks t
         WHERE t.shipment_id = s.id AND t.status IN ('open','in_progress')) AS open_tasks,
       (s.eta_initial IS NOT NULL AND s.eta IS NOT NULL AND s.eta > s.eta_initial) AS is_delayed
FROM shipments s
JOIN parties  p ON p.id = s.client_id
JOIN branches b ON b.id = s.branch_id
JOIN users    u ON u.id = s.agent_id;

CREATE VIEW v_active_delays AS
SELECT s.id, s.tenant_id, s.reference, s.client_id, s.eta, s.eta_initial,
       EXTRACT(day FROM s.eta - s.eta_initial)::int AS delay_days
FROM shipments s
WHERE s.closed_at IS NULL AND s.eta_initial IS NOT NULL AND s.eta > s.eta_initial;

CREATE VIEW v_demurrage_alerts AS
SELECT ca.id, ca.tenant_id, ca.shipment_id, ca.container_id, c.number AS container_number,
       ca.free_time_ends_at,
       EXTRACT(day FROM ca.free_time_ends_at - now())::int AS days_remaining
FROM container_assignments ca
JOIN containers c ON c.id = ca.container_id
WHERE ca.returned_at IS NULL AND ca.free_time_ends_at IS NOT NULL;

CREATE VIEW v_missing_documents AS
SELECT d.tenant_id, d.shipment_id, s.reference, d.id AS document_id, d.type, d.title
FROM documents d
JOIN shipments s ON s.id = d.shipment_id
WHERE d.status = 'missing' AND d.deleted_at IS NULL AND s.closed_at IS NULL;

CREATE VIEW v_agent_workload AS
SELECT s.tenant_id, s.agent_id, u.first_name || ' ' || u.last_name AS agent_name,
       count(*) FILTER (WHERE s.closed_at IS NULL) AS active_shipments,
       (SELECT count(*) FROM shipment_tasks t
         WHERE t.assignee_id = s.agent_id AND t.status IN ('open','in_progress')) AS open_tasks
FROM shipments s
JOIN users u ON u.id = s.agent_id
GROUP BY s.tenant_id, s.agent_id, u.first_name, u.last_name;

CREATE VIEW v_odoo_sync_health AS
SELECT tenant_id, entity_type, status, count(*) AS cnt, max(created_at) AS last_at
FROM odoo_sync_logs
WHERE created_at > now() - interval '7 days'
GROUP BY tenant_id, entity_type, status;

CREATE VIEW v_bl_consistency AS
SELECT hbl.tenant_id, hbl.id AS hbl_id, hbl.number AS hbl_number, hbl.shipment_id,
       'house_without_issued_master' AS issue
FROM bills_of_lading hbl
LEFT JOIN bills_of_lading mbl ON mbl.id = hbl.parent_id
WHERE hbl.type = 'house' AND hbl.status = 'issued'
  AND (mbl.id IS NULL OR mbl.status <> 'issued');

-- Vue matérialisée : CA opérationnel — rafraîchie par scheduler (REFRESH MATERIALIZED VIEW CONCURRENTLY).
CREATE MATERIALIZED VIEW mv_revenue_operational AS
SELECT i.tenant_id, i.company_id, date_trunc('month', i.issue_date)::date AS month,
       s.branch_id, s.mode,
       sum(i.total_excl_tax) FILTER (WHERE i.type = 'invoice')     AS invoiced,
       sum(i.total_excl_tax) FILTER (WHERE i.type = 'credit_note') AS credited,
       count(*) FILTER (WHERE i.type = 'invoice')                  AS invoice_count
FROM invoices i
LEFT JOIN shipments s ON s.id = i.shipment_id
WHERE i.status <> 'draft' AND i.type <> 'proforma' AND i.issue_date IS NOT NULL
GROUP BY i.tenant_id, i.company_id, date_trunc('month', i.issue_date), s.branch_id, s.mode;

CREATE UNIQUE INDEX ux_mv_revenue ON mv_revenue_operational
    (tenant_id, company_id, month, COALESCE(branch_id, '00000000-0000-0000-0000-000000000000'::uuid), COALESCE(mode, ''));
SQL);

        // Les vues s'exécutent avec les droits de l'appelant → RLS des tables sous-jacentes s'applique.
        DB::unprepared('GRANT SELECT ON v_shipments_list, v_active_delays, v_demurrage_alerts, v_missing_documents, v_agent_workload, v_odoo_sync_health, v_bl_consistency, mv_revenue_operational TO silaris_app');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP MATERIALIZED VIEW IF EXISTS mv_revenue_operational;
DROP VIEW IF EXISTS v_bl_consistency, v_odoo_sync_health, v_agent_workload,
    v_missing_documents, v_demurrage_alerts, v_active_delays, v_shipments_list;
SQL);

        foreach (self::TENANT_TABLES as $tableName) {
            DB::unprepared(sprintf('DROP POLICY IF EXISTS tenant_isolation ON %1$s; ALTER TABLE %1$s DISABLE ROW LEVEL SECURITY;', $tableName));
        }
    }
};
