<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('laisse le chef de service confier un dossier à son agent', function (): void {
    $ids = seedCore();
    assignService($ids, $ids['user_service_manager'], 'IMP');
    assignService($ids, $ids['user_transit_agent'], 'IMP');

    $shipmentId = shipmentReadyToAdvance($ids);

    freshAuth();
    $this->withToken(tokenFor($ids['user_service_manager']))
        ->postJson("/api/v1/shipments/{$shipmentId}/assign", ['agent_id' => $ids['user_transit_agent']])
        ->assertOk();

    expect(DB::table('shipments')->where('id', $shipmentId)->value('agent_id'))->toBe($ids['user_transit_agent']);
    // L'affectation se lit au dossier : on sait qui l'a confié, et à qui.
    expect(DB::table('shipment_events')->where('shipment_id', $shipmentId)
        ->where('title', 'like', 'Dossier confié%')->exists())->toBeTrue();
});

it('refuse d\'affecter un agent d\'un autre service', function (): void {
    $ids = seedCore();
    assignService($ids, $ids['user_service_manager'], 'IMP');
    assignService($ids, $ids['user_transit_agent'], 'IMP');
    $autre = seedUserWithRole($ids, 'transit_agent', 'agent.export@test.local');
    assignService($ids, $autre, 'EXP');

    $shipmentId = shipmentReadyToAdvance($ids);

    freshAuth();
    $this->withToken(tokenFor($ids['user_service_manager']))
        ->postJson("/api/v1/shipments/{$shipmentId}/assign", ['agent_id' => $autre])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, "n'appartient pas au service"));
});

it('ne présente au chef que les agents de son service', function (): void {
    $ids = seedCore();
    assignService($ids, $ids['user_service_manager'], 'IMP');
    assignService($ids, $ids['user_transit_agent'], 'IMP');
    assignService($ids, seedUserWithRole($ids, 'transit_agent', 'agent.export@test.local'), 'EXP');

    $agents = $this->withToken(tokenFor($ids['user_service_manager']))
        ->getJson('/api/v1/shipments/assignable-agents')->assertOk()->json('data');

    $emails = array_column($agents, 'email');
    expect($emails)->toContain('transit_agent@test.local')
        ->and($emails)->not->toContain('agent.export@test.local');
});

it('refuse l\'affectation à l\'agent lui-même', function (): void {
    $ids = seedCore();
    assignService($ids, $ids['user_transit_agent'], 'IMP');
    $shipmentId = shipmentReadyToAdvance($ids);

    freshAuth();
    // La répartition est une décision d'organisation, pas un libre-service.
    $this->withToken(tokenFor($ids['user_transit_agent']))
        ->postJson("/api/v1/shipments/{$shipmentId}/assign", ['agent_id' => $ids['user_transit_agent']])
        ->assertForbidden();
});
