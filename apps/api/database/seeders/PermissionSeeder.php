<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Catalogue des permissions atomiques + rôles système (tenant_id NULL).
 * Les rôles système sont immuables ; chaque tenant peut créer des rôles personnalisés.
 */
class PermissionSeeder extends Seeder
{
    /** module => actions disponibles */
    private const MODULES = [
        'shipments' => ['read', 'create', 'update', 'delete', 'export', 'advance', 'approve_step', 'approve_step_all', 'assign', 'close', 'reopen', 'archive'],
        'bookings' => ['read', 'create', 'update', 'delete'],
        'containers' => ['read', 'create', 'update', 'delete'],
        // Dérogations : volontairement hors du module concerné, pour qu'aucun
        // caractère générique (« shipments.* ») ne les accorde par ricochet.
        'derogations' => ['open_shipment_without_quote'],
        'bl' => ['read', 'create', 'update', 'issue'],
        'consolidations' => ['read', 'create', 'update', 'close'],
        'packages' => ['read', 'create', 'scan', 'force_delivery'],
        'awb' => ['read', 'create', 'update', 'issue'],
        'road' => ['read', 'create', 'update', 'delete', 'assign'],
        'pod' => ['read', 'create'],
        'tracking' => ['read', 'refresh', 'manual_event'],
        'crm' => ['read', 'create', 'update', 'delete', 'export', 'convert'],
        'complaints' => ['read', 'create', 'update', 'resolve'],
        'quotes' => ['read', 'create', 'update', 'delete', 'approve', 'send', 'accept'],
        'tariffs' => ['read', 'create', 'update', 'delete', 'import'],
        'invoices' => ['read', 'create', 'update', 'validate', 'credit', 'export', 'sync_odoo'],
        'payments' => ['read', 'create', 'cancel'],
        'documents' => ['read', 'create', 'update', 'delete', 'download', 'archive'],
        'notifications' => ['read', 'manage_templates'],
        'reports' => ['read', 'export', 'schedule'],
        'dashboard' => ['read', 'customize'],
        'audit' => ['read', 'export'],
        'users' => ['read', 'create', 'update', 'deactivate', 'reset_mfa'],
        'roles' => ['read', 'create', 'update', 'delete'],
        'companies' => ['read', 'create', 'update'],
        'branches' => ['read', 'create', 'update'],
        'referentials' => ['read', 'update'],
        'workflows' => ['read', 'create', 'update'],
        'settings' => ['read', 'update'],
        'api_keys' => ['read', 'create', 'revoke'],
        'webhooks' => ['read', 'create', 'update', 'delete'],
        'odoo' => ['read', 'configure', 'resolve_conflicts', 'replay'],
    ];

    /** rôle système => permissions (wildcard module.* supporté) */
    private const ROLES = [
        'super_admin' => ['label' => 'Super Administrateur', 'perms' => ['*']],
        'admin' => ['label' => 'Administrateur', 'perms' => ['*']],
        'director' => ['label' => 'Directeur', 'perms' => [
            'shipments.read', 'shipments.export', 'bookings.read', 'containers.read', 'bl.read',
            'consolidations.read', 'awb.read', 'road.read', 'pod.read', 'tracking.read',
            'crm.*', 'complaints.read', 'quotes.read', 'tariffs.read', 'invoices.read', 'invoices.export',
            'documents.read', 'documents.download', 'reports.*', 'dashboard.*', 'audit.read', 'audit.export',
            'users.read', 'odoo.read',
            // Ouverture exceptionnelle d'un dossier sans accord client : le
            // directeur en répond, la trace le nomme.
            'shipments.create', 'derogations.open_shipment_without_quote',
            // Une cotation engage un prix : elle passe sous ses yeux avant de
            // quitter la maison.
            'quotes.approve',
        ]],
        'service_manager' => ['label' => 'Chef de service', 'perms' => [
            // Même périmètre opérationnel qu'un agent, plus la validation des
            // passages d'étape — bornée à son service par le contrôle d'accès.
            'packages.*',
            'shipments.read', 'shipments.create', 'shipments.update', 'shipments.advance',
            'shipments.approve_step', 'shipments.assign', 'shipments.export',
            'bookings.*', 'containers.*', 'bl.*', 'consolidations.*', 'awb.*',
            'road.*', 'pod.*', 'tracking.*', 'crm.read', 'complaints.*',
            'quotes.read', 'invoices.read', 'invoices.create',
            'documents.*', 'notifications.read', 'reports.read', 'dashboard.*',
        ]],
        'ops_manager' => ['label' => 'Responsable transit / exploitation', 'perms' => [
            'packages.*',
            'shipments.*', 'bookings.*', 'containers.*', 'bl.*', 'consolidations.*', 'awb.*',
            'road.*', 'pod.*', 'tracking.*', 'crm.read', 'complaints.*', 'quotes.read',
            'invoices.read', 'invoices.create', 'invoices.validate',
            'documents.*', 'notifications.read', 'reports.read', 'reports.export', 'dashboard.*', 'users.read',
        ]],
        'transit_agent' => ['label' => 'Agent Transit', 'perms' => [
            'packages.read', 'packages.create', 'packages.scan',
            // Il tient les dossiers et propose leur franchissement d'étape,
            // mais ne les ouvre pas : la création revient au chef de service.
            'shipments.read', 'shipments.update', 'shipments.advance',
            'bookings.*', 'containers.*', 'bl.read', 'bl.create', 'bl.update', 'consolidations.read', 'consolidations.create', 'consolidations.update',
            'awb.read', 'awb.create', 'awb.update', 'road.read', 'road.create', 'road.update', 'pod.read',
            'tracking.*', 'crm.read', 'complaints.read', 'complaints.create',
            'quotes.read', 'invoices.read', 'invoices.create',
            'documents.read', 'documents.create', 'documents.update', 'documents.download',
            'notifications.read', 'dashboard.read', 'dashboard.customize',
        ]],
        'sales' => ['label' => 'Commercial', 'perms' => [
            'crm.*', 'complaints.read', 'complaints.create', 'quotes.*', 'tariffs.read',
            'shipments.read', 'tracking.read', 'invoices.read',
            'documents.read', 'documents.download', 'notifications.read', 'dashboard.read', 'dashboard.customize',
            'reports.read',
        ]],
        'accountant' => ['label' => 'Comptable', 'perms' => [
            'invoices.read', 'invoices.export', 'invoices.sync_odoo',
            // Il encaisse et impute ; l'annulation d'un règlement déjà porté en
            // caisse relève du responsable financier.
            'payments.read', 'payments.create',
            // Il tient les données de facturation du client — RCCM, adresse,
            // conditions de règlement — et doit donc pouvoir les corriger.
            'quotes.read', 'crm.read', 'crm.create', 'crm.update', 'shipments.read',
            'odoo.*', 'reports.read', 'reports.export', 'dashboard.read', 'documents.read', 'documents.download',
        ]],
        'finance_manager' => ['label' => 'Responsable financier', 'perms' => [
            'packages.force_delivery',
            'invoices.*', 'payments.*', 'quotes.read', 'tariffs.read', 'crm.read', 'crm.create', 'crm.update',
            'shipments.read', 'odoo.*', 'reports.*', 'dashboard.*', 'audit.read',
            'documents.read', 'documents.download', 'notifications.read',
        ]],
        'sales_manager' => ['label' => 'Responsable commercial', 'perms' => [
            'crm.*', 'complaints.*', 'quotes.*', 'tariffs.*',
            'shipments.read', 'tracking.read', 'invoices.read',
            'documents.read', 'documents.download', 'reports.*', 'dashboard.*',
            'notifications.read', 'users.read',
        ]],
        'warehouse' => ['label' => 'Réceptionnaire / Magasinier', 'perms' => [
            'shipments.read', 'consolidations.*', 'packages.read', 'packages.create', 'packages.scan', 'containers.read', 'containers.update',
            'tracking.read', 'tracking.manual_event',
            'documents.read', 'documents.create', 'documents.download',
            'pod.read', 'pod.create', 'notifications.read', 'dashboard.read',
        ]],
        'driver' => ['label' => 'Chauffeur', 'perms' => [
            'road.read', 'pod.read', 'pod.create', 'notifications.read',
        ]],
    ];

    public function run(): void
    {
        $now = now();

        // 1. Permissions atomiques
        $allKeys = [];
        foreach (self::MODULES as $module => $actions) {
            foreach ($actions as $action) {
                $key = "{$module}.{$action}";
                $allKeys[] = $key;
                DB::table('permissions')->updateOrInsert(
                    ['key' => $key],
                    ['module' => $module, 'label' => ucfirst($action).' — '.$module,
                        'created_at' => $now, 'updated_at' => $now],
                );
            }
        }

        // 2. Rôles système + affectations
        foreach (self::ROLES as $roleKey => $def) {
            $roleId = DB::table('roles')->whereNull('tenant_id')->where('key', $roleKey)->value('id');
            if ($roleId !== null) {
                DB::table('roles')->where('id', $roleId)->update(['name' => $def['label'], 'updated_at' => $now]);
            }
            if ($roleId === null) {
                $roleId = (string) Str::uuid7();
                DB::table('roles')->insert([
                    'id' => $roleId, 'tenant_id' => null, 'key' => $roleKey,
                    'name' => $def['label'], 'is_system' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $resolved = [];
            foreach ($def['perms'] as $perm) {
                if ($perm === '*') {
                    $resolved = $allKeys;
                    break;
                }
                if (str_ends_with($perm, '.*')) {
                    $module = substr($perm, 0, -2);
                    $resolved = [...$resolved, ...array_filter($allKeys, fn ($k) => str_starts_with($k, "{$module}."))];
                } else {
                    $resolved[] = $perm;
                }
            }

            DB::table('role_permissions')->where('role_id', $roleId)->delete();
            DB::table('role_permissions')->insert(
                array_map(fn ($k) => ['role_id' => $roleId, 'permission_key' => $k], array_values(array_unique($resolved))),
            );
        }
    }
}
