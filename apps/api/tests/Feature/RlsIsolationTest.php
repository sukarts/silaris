<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * RLS réellement effective sous le rôle applicatif silaris_app (FORCE + fail-closed).
 * Les tests tournent en superuser (bypass RLS) ; on bascule de rôle pour éprouver la DB.
 * Table témoin : parties (RLS, sans trigger de dépendance).
 */
function underAppRole(callable $fn): mixed
{
    DB::statement('GRANT silaris_app TO current_user');
    DB::statement('SET ROLE silaris_app');
    try {
        return $fn();
    } finally {
        DB::statement('RESET ROLE');
    }
}

function makeTenantWithParty(): array
{
    $tenant = (string) Str::uuid7();
    DB::table('tenants')->insert(['id' => $tenant, 'name' => 'T', 'slug' => 't-'.Str::random(10), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('parties')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenant, 'type' => 'client', 'kind' => 'company',
        'code' => 'C'.Str::random(6), 'name' => 'Client', 'notification_prefs' => '{}', 'tags' => '[]',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$tenant];
}

it('RLS fail-closed : sans contexte tenant, aucune ligne visible et aucune erreur', function (): void {
    makeTenantWithParty();

    $count = underAppRole(function () {
        DB::statement("SELECT set_config('app.tenant_id', '', false)");

        return DB::table('parties')->count();
    });

    expect($count)->toBe(0);
});

it('RLS isole en lecture et refuse l ecriture cross-tenant sous le role applicatif', function (): void {
    [$a] = makeTenantWithParty();
    [$b] = makeTenantWithParty();

    [$seenByA, $crossWriteBlocked] = underAppRole(function () use ($a, $b) {
        DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$a]);
        $seen = DB::table('parties')->count();

        $blocked = false;
        DB::statement('SAVEPOINT rls_probe');
        try {
            DB::table('parties')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $b, 'type' => 'client', 'kind' => 'company',
                'code' => 'INTRUS', 'name' => 'X', 'notification_prefs' => '{}', 'tags' => '[]',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::statement('RELEASE SAVEPOINT rls_probe');
        } catch (Throwable) {
            $blocked = true;
            DB::statement('ROLLBACK TO SAVEPOINT rls_probe'); // désarme l'abort transactionnel
        }

        return [$seen, $blocked];
    });

    expect($seenByA)->toBe(1)->and($crossWriteBlocked)->toBeTrue();
});
