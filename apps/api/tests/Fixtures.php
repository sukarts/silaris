<?php

declare(strict_types=1);

use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Silaris\Modules\Crm\Infrastructure\Persistence\Model\PortalAccountModel;
use Silaris\Modules\Identity\Infrastructure\Persistence\Model\UserModel;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;

/**
 * Fixture cœur : tenant + org + rôles système + utilisateurs + workflow + client.
 * Retourne un annuaire d'ids + tokens Sanctum prêts à l'emploi.
 */
function seedCore(): array
{
    (new PermissionSeeder)->run();

    $ids = ['tenant' => (string) Str::uuid7()];
    DB::table('tenants')->insert(['id' => $ids['tenant'], 'name' => 'Test Co', 'slug' => 't-'.Str::random(6), 'settings' => json_encode(['delay_threshold_hours' => 24]), 'created_at' => now(), 'updated_at' => now()]);
    app(TenantContext::class)->set($ids['tenant']);

    DB::table('currencies')->insertOrIgnore([['code' => 'XOF', 'name' => 'FCFA', 'symbol' => 'F', 'decimals' => 0, 'created_at' => now(), 'updated_at' => now()]]);
    DB::table('incoterms')->insertOrIgnore([['code' => 'CIF', 'label' => 'Cost Insurance Freight', 'version' => '2020', 'cost_allocation' => '{}', 'created_at' => now(), 'updated_at' => now()]]);

    $ids['company'] = (string) Str::uuid7();
    DB::table('companies')->insert(['id' => $ids['company'], 'tenant_id' => $ids['tenant'], 'legal_name' => 'Test SA', 'code' => 'TST', 'currency_code' => 'XOF', 'invoice_settings' => json_encode(['number_format' => 'F-{YEAR}-{SEQ:4}']), 'created_at' => now(), 'updated_at' => now()]);
    $ids['branch'] = (string) Str::uuid7();
    DB::table('branches')->insert(['id' => $ids['branch'], 'tenant_id' => $ids['tenant'], 'company_id' => $ids['company'], 'name' => 'Agence Test', 'code' => 'TSA', 'created_at' => now(), 'updated_at' => now()]);

    // Workflow standard 4 étapes suffisant pour les tests
    $ids['workflow'] = (string) Str::uuid7();
    DB::table('workflow_definitions')->insert(['id' => $ids['workflow'], 'tenant_id' => $ids['tenant'], 'name' => 'Std', 'transport_mode' => 'any', 'direction' => 'any', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
    foreach ([
        ['creation', ['booking'], []],
        ['booking', ['departure'], ['required_documents' => ['commercial_invoice']]],
        ['departure', ['closure'], []],
        ['closure', [], ['requires' => ['invoice_issued']]],
    ] as $position => [$key, $transitions, $conditions]) {
        DB::table('workflow_steps')->insert(['id' => (string) Str::uuid7(), 'workflow_definition_id' => $ids['workflow'], 'key' => $key, 'label' => ucfirst($key), 'position' => $position + 1, 'transitions' => json_encode($transitions), 'conditions' => json_encode($conditions), 'actions' => '[]', 'created_at' => now(), 'updated_at' => now()]);
    }

    $roles = DB::table('roles')->whereNull('tenant_id')->pluck('id', 'key');
    foreach (['admin', 'transit_agent', 'accountant', 'driver', 'director', 'ops_manager', 'service_manager', 'sales_manager'] as $roleKey) {
        $userId = (string) Str::uuid7();
        $ids["user_{$roleKey}"] = $userId;
        DB::table('users')->insert(['id' => $userId, 'tenant_id' => $ids['tenant'], 'email' => "{$roleKey}@test.local", 'password_hash' => Hash::make('Str0ng!Passw0rd'), 'first_name' => ucfirst($roleKey), 'last_name' => 'Test', 'password_changed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_roles')->insert(['user_id' => $userId, 'role_id' => $roles[$roleKey]]);
        DB::table('user_branches')->insert(['user_id' => $userId, 'branch_id' => $ids['branch']]);
    }

    $ids['client'] = (string) Str::uuid7();
    DB::table('parties')->insert(['id' => $ids['client'], 'tenant_id' => $ids['tenant'], 'type' => 'client', 'code' => 'CLI1', 'name' => 'Client Un', 'payment_terms_days' => 30, 'notification_prefs' => '{}', 'tags' => '[]', 'created_at' => now(), 'updated_at' => now()]);

    $ids['portal'] = (string) Str::uuid7();
    DB::table('portal_accounts')->insert(['id' => $ids['portal'], 'tenant_id' => $ids['tenant'], 'party_id' => $ids['client'], 'email' => 'client@test.local', 'password_hash' => Hash::make('Str0ng!Passw0rd'), 'name' => 'Portail Client', 'notification_prefs' => '{}', 'created_at' => now(), 'updated_at' => now()]);

    return $ids;
}

function tokenFor(string $userId): string
{
    $user = UserModel::withoutGlobalScopes()->findOrFail($userId);

    return $user->createToken('test')->plainTextToken;
}

function portalTokenFor(string $accountId): string
{
    $account = PortalAccountModel::withoutGlobalScopes()->findOrFail($accountId);

    return $account->createToken('test', ['portal'])->plainTextToken;
}

/** Réinitialise les guards entre deux requêtes d'un même test (cache Sanctum). */
function freshAuth(): void
{
    app('auth')->forgetGuards();
}
