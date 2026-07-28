<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** Corps de création sans les conditions : elles viennent de la cotation. */
function openShipment(array $ids, string $quoteId): array
{
    return [
        'client_id' => $ids['client'], 'branch_id' => $ids['branch'], 'company_id' => $ids['company'],
        'agent_id' => $ids['user_transit_agent'], 'quote_id' => $quoteId,
    ];
}

it('refuse d\'ouvrir un dossier sans cotation', function (): void {
    $ids = seedCore();

    $this->withToken(tokenFor($ids['user_transit_agent']))
        ->postJson('/api/v1/shipments', [
            'client_id' => $ids['client'], 'branch_id' => $ids['branch'],
            'company_id' => $ids['company'], 'agent_id' => $ids['user_transit_agent'],
        ])->assertStatus(422)->assertJsonPath('errors.quote_id.0', 'The quote id field is required.');
});

it('refuse une cotation que le client n\'a pas encore acceptée', function (): void {
    $ids = seedCore();
    $quoteId = seedAcceptedQuote($ids, overrides: ['status' => 'sent', 'accepted_at' => null]);

    $this->withToken(tokenFor($ids['user_transit_agent']))
        ->postJson('/api/v1/shipments', openShipment($ids, $quoteId))
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'shipment.quote_not_accepted')
        ->assertJsonPath('detail', fn (string $detail) => str_contains($detail, 'attend la réponse du client'));
});

it('refuse une cotation refusée par le client', function (): void {
    $ids = seedCore();
    $quoteId = seedAcceptedQuote($ids, overrides: ['status' => 'rejected', 'accepted_at' => null]);

    $this->withToken(tokenFor($ids['user_transit_agent']))
        ->postJson('/api/v1/shipments', openShipment($ids, $quoteId))
        ->assertStatus(422)
        ->assertJsonPath('detail', fn (string $detail) => str_contains($detail, 'refusée par le client'));
});

it('refuse la cotation d\'un autre client', function (): void {
    $ids = seedCore();
    $otherClient = (string) Str::uuid7();
    DB::table('parties')->insert([
        'id' => $otherClient, 'tenant_id' => $ids['tenant'], 'type' => 'client', 'code' => 'CLI9',
        'name' => 'Client Neuf', 'payment_terms_days' => 30, 'notification_prefs' => '{}', 'tags' => '[]',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $quoteId = seedAcceptedQuote($ids, clientId: $otherClient);

    $this->withToken(tokenFor($ids['user_transit_agent']))
        ->postJson('/api/v1/shipments', openShipment($ids, $quoteId))
        ->assertStatus(422)
        ->assertJsonPath('detail', fn (string $detail) => str_contains($detail, 'autre client'));
});

it('n\'ouvre qu\'un seul dossier par cotation', function (): void {
    $ids = seedCore();
    $token = tokenFor($ids['user_transit_agent']);
    $quoteId = seedAcceptedQuote($ids);

    $first = $this->withToken($token)->postJson('/api/v1/shipments', openShipment($ids, $quoteId))
        ->assertCreated()->json('data.reference');

    $this->withToken($token)->postJson('/api/v1/shipments', openShipment($ids, $quoteId))
        ->assertStatus(422)
        ->assertJsonPath('detail', fn (string $detail) => str_contains($detail, $first));
});

it('reprend de la cotation les conditions convenues, pas celles envoyées', function (): void {
    $ids = seedCore();
    $quoteId = seedAcceptedQuote($ids, overrides: [
        'direction' => 'export', 'mode' => 'air', 'incoterm_code' => 'CIF',
        'origin_locode' => 'CIABJ', 'destination_locode' => 'FRCDG',
        'currency_code' => 'XOF', 'total_amount' => 2_400_000,
    ]);

    // L'exploitant se trompe de sens et de mode : la cotation fait foi.
    $shipment = $this->withToken(tokenFor($ids['user_transit_agent']))
        ->postJson('/api/v1/shipments', [
            ...openShipment($ids, $quoteId),
            'direction' => 'import', 'mode' => 'sea_fcl',
            'origin_locode' => 'CNSHA', 'destination_locode' => 'CIABJ',
        ])->assertCreated()->json('data');

    expect($shipment['direction'])->toBe('export')
        ->and($shipment['mode'])->toBe('air')
        ->and($shipment['origin_locode'])->toBe('CIABJ')
        ->and($shipment['destination_locode'])->toBe('FRCDG');

    // Le chiffre d'affaires convenu ouvre le dossier : la marge est lisible d'emblée.
    $row = DB::table('shipments')->where('id', $shipment['id'])->first(['estimated_revenue', 'currency_code']);
    expect((float) $row->estimated_revenue)->toBe(2_400_000.0)
        ->and($row->currency_code)->toBe('XOF');
});
