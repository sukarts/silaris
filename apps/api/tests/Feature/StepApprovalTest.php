<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** Dossier ouvert sur cotation, prêt à franchir sa première étape. */
function shipmentReadyToAdvance(array $ids): string
{
    // Seul un chef de service ouvre un dossier ; l'agent le tient ensuite.
    $shipmentId = test()->withToken(tokenFor($ids['user_service_manager']))->postJson('/api/v1/shipments', [
        'client_id' => $ids['client'], 'branch_id' => $ids['branch'], 'company_id' => $ids['company'],
        'agent_id' => $ids['user_transit_agent'], 'quote_id' => seedAcceptedQuote($ids),
    ])->json('data.id');
    freshAuth();

    // Le document exigé à l'étape suivante, pour n'éprouver que la validation.
    DB::table('documents')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $ids['tenant'], 'shipment_id' => $shipmentId,
        'type' => 'commercial_invoice', 'status' => 'received', 'title' => 'Facture commerciale',
        'visibility' => 'internal', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $shipmentId;
}

it('transforme l\'avancement de l\'agent en demande de validation', function (): void {
    $ids = seedCore();
    $shipmentId = shipmentReadyToAdvance($ids);

    $response = $this->withToken(tokenFor($ids['user_transit_agent']))
        ->postJson("/api/v1/shipments/{$shipmentId}/advance", ['next_step' => 'booking'])
        ->assertStatus(202)->assertJsonPath('status', 'pending_approval');

    // Le dossier n'a pas bougé : la demande ne vaut pas franchissement.
    expect(DB::table('shipments')->where('id', $shipmentId)->value('status'))->toBe('creation')
        ->and(DB::table('shipment_step_requests')->where('id', $response->json('request_id'))->value('status'))
        ->toBe('pending');
});

it('laisse le responsable exploitation avancer sans intermédiaire', function (): void {
    $ids = seedCore();
    $shipmentId = shipmentReadyToAdvance($ids);

    freshAuth();
    $this->withToken(tokenFor($ids['user_ops_manager']))
        ->postJson("/api/v1/shipments/{$shipmentId}/advance", ['next_step' => 'booking'])
        ->assertOk();

    expect(DB::table('shipments')->where('id', $shipmentId)->value('status'))->toBe('booking')
        ->and(DB::table('shipment_step_requests')->count())->toBe(0);
});

it('franchit réellement l\'étape à la validation', function (): void {
    $ids = seedCore();
    $shipmentId = shipmentReadyToAdvance($ids);
    $requestId = $this->withToken(tokenFor($ids['user_transit_agent']))
        ->postJson("/api/v1/shipments/{$shipmentId}/advance", ['next_step' => 'booking'])->json('request_id');

    freshAuth();
    $this->withToken(tokenFor($ids['user_ops_manager']))
        ->postJson("/api/v1/shipments/step-requests/{$requestId}/decide", ['decision' => 'approved'])
        ->assertOk()->assertJsonPath('step', 'booking');

    expect(DB::table('shipments')->where('id', $shipmentId)->value('status'))->toBe('booking')
        ->and(DB::table('shipment_step_requests')->where('id', $requestId)->value('status'))->toBe('approved');
});

it('laisse le dossier en place quand le responsable refuse', function (): void {
    $ids = seedCore();
    $shipmentId = shipmentReadyToAdvance($ids);
    $requestId = $this->withToken(tokenFor($ids['user_transit_agent']))
        ->postJson("/api/v1/shipments/{$shipmentId}/advance", ['next_step' => 'booking'])->json('request_id');

    freshAuth();
    $this->withToken(tokenFor($ids['user_ops_manager']))
        ->postJson("/api/v1/shipments/step-requests/{$requestId}/decide", [
            'decision' => 'rejected', 'note' => 'Booking non confirmé par la compagnie.',
        ])->assertOk();

    expect(DB::table('shipments')->where('id', $shipmentId)->value('status'))->toBe('creation');

    // La voie se rouvre : l'agent peut proposer à nouveau une fois corrigé.
    freshAuth();
    $this->withToken(tokenFor($ids['user_transit_agent']))
        ->postJson("/api/v1/shipments/{$shipmentId}/advance", ['next_step' => 'booking'])
        ->assertStatus(202);
});

it('n\'ouvre qu\'une demande à la fois par dossier', function (): void {
    $ids = seedCore();
    $shipmentId = shipmentReadyToAdvance($ids);
    $token = tokenFor($ids['user_transit_agent']);

    $this->withToken($token)->postJson("/api/v1/shipments/{$shipmentId}/advance", ['next_step' => 'booking'])
        ->assertStatus(202);
    $this->withToken($token)->postJson("/api/v1/shipments/{$shipmentId}/advance", ['next_step' => 'booking'])
        ->assertStatus(422)->assertJsonPath('error_code', 'shipment.step_request_pending');
});

it('réserve la file et la décision au responsable exploitation', function (): void {
    $ids = seedCore();
    $shipmentId = shipmentReadyToAdvance($ids);
    $requestId = $this->withToken(tokenFor($ids['user_transit_agent']))
        ->postJson("/api/v1/shipments/{$shipmentId}/advance", ['next_step' => 'booking'])->json('request_id');

    $this->withToken(tokenFor($ids['user_transit_agent']))->getJson('/api/v1/shipments/step-requests')
        ->assertForbidden();
    $this->withToken(tokenFor($ids['user_transit_agent']))
        ->postJson("/api/v1/shipments/step-requests/{$requestId}/decide", ['decision' => 'approved'])
        ->assertForbidden();
});

it('présente au responsable les demandes en attente', function (): void {
    $ids = seedCore();
    $shipmentId = shipmentReadyToAdvance($ids);
    $this->withToken(tokenFor($ids['user_transit_agent']))
        ->postJson("/api/v1/shipments/{$shipmentId}/advance", ['next_step' => 'booking']);

    freshAuth();
    $queue = $this->withToken(tokenFor($ids['user_ops_manager']))
        ->getJson('/api/v1/shipments/step-requests')->assertOk()->json('data');

    expect($queue)->toHaveCount(1)
        ->and($queue[0]['from_step'])->toBe('creation')
        ->and($queue[0]['to_step'])->toBe('booking')
        ->and($queue[0]['requested_by'])->not->toBe('—');
});

/** Rattache un utilisateur à un service, et renvoie l'identifiant du service. */
function assignService(array $ids, string $userId, string $code): string
{
    $serviceId = DB::table('services')->where('tenant_id', $ids['tenant'])->where('code', $code)->value('id');
    if ($serviceId === null) {
        $serviceId = (string) Str::uuid7();
        DB::table('services')->insert([
            'id' => $serviceId, 'tenant_id' => $ids['tenant'], 'code' => $code,
            'name' => $code, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    DB::table('users')->where('id', $userId)->update(['service_id' => $serviceId]);

    return (string) $serviceId;
}

it('laisse le chef de service valider les dossiers de son service', function (): void {
    $ids = seedCore();
    $chef = seedUserWithRole($ids, 'service_manager', 'chef.import@test.local');
    $service = assignService($ids, $ids['user_transit_agent'], 'IMP');
    assignService($ids, $chef, 'IMP');

    $shipmentId = shipmentReadyToAdvance($ids);
    $requestId = test()->withToken(tokenFor($ids['user_transit_agent']))
        ->postJson("/api/v1/shipments/{$shipmentId}/advance", ['next_step' => 'booking'])->json('request_id');

    freshAuth();
    $this->withToken(tokenFor($chef))
        ->postJson("/api/v1/shipments/step-requests/{$requestId}/decide", ['decision' => 'approved'])
        ->assertOk();

    expect(DB::table('shipments')->where('id', $shipmentId)->value('status'))->toBe('booking')
        ->and(DB::table('shipments')->where('id', $shipmentId)->value('service_id'))->toBe($service);
});

it('refuse au chef de service les dossiers d\'un autre service', function (): void {
    $ids = seedCore();
    $chefExport = seedUserWithRole($ids, 'service_manager', 'chef.export@test.local');
    assignService($ids, $ids['user_transit_agent'], 'IMP');
    assignService($ids, $chefExport, 'EXP');

    $shipmentId = shipmentReadyToAdvance($ids);
    $requestId = test()->withToken(tokenFor($ids['user_transit_agent']))
        ->postJson("/api/v1/shipments/{$shipmentId}/advance", ['next_step' => 'booking'])->json('request_id');

    freshAuth();
    // Le chef export n'a rien à connaître d'un dossier import.
    $this->withToken(tokenFor($chefExport))
        ->postJson("/api/v1/shipments/step-requests/{$requestId}/decide", ['decision' => 'approved'])
        ->assertForbidden();

    freshAuth();
    expect($this->withToken(tokenFor($chefExport))->getJson('/api/v1/shipments/step-requests')->json('data'))
        ->toBeEmpty();

    expect(DB::table('shipments')->where('id', $shipmentId)->value('status'))->toBe('creation');
});

it('laisse le responsable exploitation trancher tous les services', function (): void {
    $ids = seedCore();
    assignService($ids, $ids['user_transit_agent'], 'IMP');

    $shipmentId = shipmentReadyToAdvance($ids);
    $requestId = test()->withToken(tokenFor($ids['user_transit_agent']))
        ->postJson("/api/v1/shipments/{$shipmentId}/advance", ['next_step' => 'booking'])->json('request_id');

    // Sans service de rattachement, mais avec la portée globale.
    freshAuth();
    $this->withToken(tokenFor($ids['user_ops_manager']))
        ->postJson("/api/v1/shipments/step-requests/{$requestId}/decide", ['decision' => 'approved'])
        ->assertOk();

    expect(DB::table('shipments')->where('id', $shipmentId)->value('status'))->toBe('booking');
});
