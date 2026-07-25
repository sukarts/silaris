<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Durcissement RLS :
 *  1. Policies « fail-closed » : sans app.tenant_id positionné, current_setting(...,true)
 *     renvoie NULL (missing_ok) → aucune ligne visible (au lieu d'une erreur 42704).
 *  2. FORCE ROW LEVEL SECURITY : la RLS s'applique même si l'app se connecte en
 *     propriétaire de table (défense en profondeur réellement effective).
 *
 * Les rares requêtes délibérément cross-tenant (résolution de login, suivi public,
 * journal de téléchargement) passent par la connexion `pgsql_system` (rôle bypass),
 * jamais par la connexion applicative.
 */
return new class extends Migration
{
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
        'packages',
    ];

    public function up(): void
    {
        foreach (self::TENANT_TABLES as $table) {
            DB::unprepared(sprintf(<<<'SQL'
DROP POLICY IF EXISTS tenant_isolation ON %1$s;
CREATE POLICY tenant_isolation ON %1$s
    USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
    WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
ALTER TABLE %1$s FORCE ROW LEVEL SECURITY;
SQL, $table));
        }
    }

    public function down(): void
    {
        foreach (self::TENANT_TABLES as $table) {
            DB::unprepared(sprintf(<<<'SQL'
ALTER TABLE %1$s NO FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tenant_isolation ON %1$s;
CREATE POLICY tenant_isolation ON %1$s
    USING (tenant_id = current_setting('app.tenant_id')::uuid)
    WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid);
SQL, $table));
        }
    }
};
