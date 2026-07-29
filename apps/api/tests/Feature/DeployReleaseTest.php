<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('installe les rôles et permissions que le code déclare', function (): void {
    // Une version qui ajoute un rôle doit le voir apparaître en production
    // sans intervention manuelle : c'est ce que la commande garantit.
    DB::table('role_permissions')->delete();
    DB::table('roles')->whereNull('tenant_id')->delete();

    $this->artisan('silaris:deploy')->assertSuccessful();

    $roles = DB::table('roles')->whereNull('tenant_id')->pluck('key');
    expect($roles)->toContain('service_manager')
        ->and($roles)->toContain('ops_manager')
        ->and($roles)->toContain('director');

    $chef = DB::table('roles')->whereNull('tenant_id')->where('key', 'service_manager')->value('id');
    $keys = DB::table('role_permissions')->where('role_id', $chef)->pluck('permission_key');
    expect($keys)->toContain('shipments.approve_step')
        ->and($keys)->toContain('shipments.assign')
        ->and($keys)->toContain('shipments.create');
});

it('retire d\'un rôle une permission que le code ne lui donne plus', function (): void {
    $this->artisan('silaris:deploy')->assertSuccessful();
    $agent = DB::table('roles')->whereNull('tenant_id')->where('key', 'transit_agent')->value('id');
    DB::table('role_permissions')->insert(['role_id' => $agent, 'permission_key' => 'shipments.create']);

    $this->artisan('silaris:deploy')->assertSuccessful();

    // La création de dossier revient au chef de service : le déploiement doit
    // corriger un droit accordé par erreur, pas seulement en ajouter.
    expect(DB::table('role_permissions')->where('role_id', $agent)
        ->where('permission_key', 'shipments.create')->exists())->toBeFalse();
});

it('laisse intacts les rôles propres au transitaire', function (): void {
    $ids = seedCore();
    $custom = (string) Str::uuid7();
    DB::table('roles')->insert([
        'id' => $custom, 'tenant_id' => $ids['tenant'], 'key' => 'facturation_export',
        'name' => 'Facturation export', 'is_system' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('role_permissions')->insert(['role_id' => $custom, 'permission_key' => 'invoices.read']);

    $this->artisan('silaris:deploy')->assertSuccessful();

    expect(DB::table('role_permissions')->where('role_id', $custom)->count())->toBe(1)
        ->and(DB::table('roles')->where('id', $custom)->exists())->toBeTrue();
});
