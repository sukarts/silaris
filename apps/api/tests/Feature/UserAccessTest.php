<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('affecte un service à un utilisateur et le renvoie dans la liste', function (): void {
    $ids = seedCore();
    $serviceId = (string) Str::uuid7();
    DB::table('services')->insert([
        'id' => $serviceId, 'tenant_id' => $ids['tenant'], 'code' => 'MAR', 'name' => 'Maritime',
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $token = tokenFor($ids['user_admin']);
    $target = $ids['user_transit_agent'];

    $this->withToken($token)->patchJson("/api/v1/admin/users/{$target}", [
        'service_id' => $serviceId,
    ])->assertOk();

    // Le service doit revenir dans la liste — sans quoi il paraît « ne pas se fixer ».
    $users = collect($this->withToken($token)->getJson('/api/v1/admin/users')->assertOk()->json('data'));
    $row = $users->firstWhere('id', $target);

    expect($row['service']['id'])->toBe($serviceId)
        ->and($row['service']['name'])->toBe('Maritime');
});
