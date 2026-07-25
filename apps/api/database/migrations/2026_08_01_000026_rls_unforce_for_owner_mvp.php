<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retire FORCE ROW LEVEL SECURITY (garde ENABLE + policies fail-closed).
 *
 * Contexte : sur l'hébergement managé (Render), l'application se connecte en
 * PROPRIÉTAIRE des tables et aucun rôle BYPASSRLS n'est possible (pas de
 * superuser). Avec FORCE, la RLS mord aussi le propriétaire → la « connexion
 * système » (login pré-auth, tracking public) ne voit plus `users` → connexion
 * impossible en production.
 *
 * FORCE ne protège QUE contre le propriétaire :
 *  - MVP (app = propriétaire) : isolation assurée par les scopes applicatifs
 *    (BelongsToTenant + TenantContext), RLS inactive pour l'app-propriétaire.
 *  - Prod durcie (P1) : app connectée en rôle NON-propriétaire (silaris_login)
 *    → ENABLE suffit, les policies s'appliquent pleinement, défense DB entière.
 *    (RlsIsolationTest tourne sous rôle non-propriétaire : garanties intactes.)
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
            DB::unprepared(sprintf('ALTER TABLE %s NO FORCE ROW LEVEL SECURITY;', $table));
        }
    }

    public function down(): void
    {
        foreach (self::TENANT_TABLES as $table) {
            DB::unprepared(sprintf('ALTER TABLE %s FORCE ROW LEVEL SECURITY;', $table));
        }
    }
};
