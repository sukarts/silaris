<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('expose le suivi public sans données sensibles et rejette les entrées invalides', function (): void {
    $ids = seedCore();
    seedShipmentFor($ids, $ids['client'], 'TST-2026-00042');

    freshAuth();
    $ok = $this->getJson('/api/v1/public/tracking?q=TST-2026-00042');
    $ok->assertOk()->assertJsonPath('reference', 'TST-2026-00042');
    expect($ok->json())->not->toHaveKeys(['estimated_revenue', 'client', 'notes']);

    $this->getJson('/api/v1/public/tracking?q=INCONNU-999999')->assertNotFound();
    $this->getJson('/api/v1/public/tracking?q=ab')->assertUnprocessable();
});

it('cloisonne le portail : chaque client ne voit que ses dossiers, token interne rejeté', function (): void {
    $ids = seedCore();

    // Deuxième client avec son dossier
    $otherClient = (string) Str::uuid7();
    DB::table('parties')->insert(['id' => $otherClient, 'tenant_id' => $ids['tenant'], 'type' => 'client', 'code' => 'CLI2', 'name' => 'Client Deux', 'notification_prefs' => '{}', 'tags' => '[]', 'created_at' => now(), 'updated_at' => now()]);
    seedShipmentFor($ids, $ids['client'], 'TST-2026-00100');
    seedShipmentFor($ids, $otherClient, 'TST-2026-00200');

    $portalToken = portalTokenFor($ids['portal']);
    freshAuth();
    $list = $this->withToken($portalToken)->getJson('/api/v1/portal/shipments');
    $list->assertOk();
    $references = array_column($list->json('data'), 'reference');
    expect($references)->toContain('TST-2026-00100')->not->toContain('TST-2026-00200');

    // Token interne sur routes portail → 403 ; token portail sur routes internes → 403
    freshAuth();
    $this->withToken(tokenFor($ids['user_admin']))->getJson('/api/v1/portal/shipments')->assertForbidden();
    freshAuth();
    $this->withToken($portalToken)->getJson('/api/v1/shipments')->assertForbidden();
});
