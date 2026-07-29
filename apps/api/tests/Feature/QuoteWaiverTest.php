<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Ouverture dérogatoire : conditions déclarées et motif d'urgence. */
function waiverPayload(array $ids, array $overrides = []): array
{
    return [
        'client_id' => $ids['client'], 'branch_id' => $ids['branch'], 'company_id' => $ids['company'],
        'agent_id' => $ids['user_transit_agent'],
        'direction' => 'import', 'mode' => 'sea_fcl', 'incoterm_code' => 'CIF',
        'origin_locode' => 'CNSHA', 'destination_locode' => 'CIABJ',
        'waiver_reason' => 'Conteneur déjà à quai, cotation en cours de signature chez le client.',
        ...$overrides,
    ];
}

it('laisse l\'exploitant soumettre une ouverture, en attente de la direction', function (): void {
    $ids = seedCore();

    $shipmentId = $this->withToken(tokenFor($ids['user_service_manager']))
        ->postJson('/api/v1/shipments', waiverPayload($ids))
        ->assertCreated()->json('data.id');

    $row = DB::table('shipments')->where('id', $shipmentId)
        ->first(['quote_waiver_status', 'quote_waiver_reason', 'quote_waiver_requested_by']);

    expect($row->quote_waiver_status)->toBe('pending')
        ->and($row->quote_waiver_requested_by)->toBe($ids['user_transit_agent'])
        ->and($row->quote_waiver_reason)->toContain('déjà à quai');

    // La demande se lit sur le dossier, pas seulement en base.
    expect(DB::table('shipment_events')->where('shipment_id', $shipmentId)
        ->where('title', 'like', 'Ouverture sans cotation%')->exists())->toBeTrue();
});

it('exige un motif circonstancié, pas un mot', function (): void {
    $ids = seedCore();

    $this->withToken(tokenFor($ids['user_service_manager']))
        ->postJson('/api/v1/shipments', waiverPayload($ids, ['waiver_reason' => 'urgent']))
        ->assertStatus(422)->assertJsonPath('errors.waiver_reason.0', 'The waiver reason field must be at least 15 characters.');
});

it('bloque l\'avancement tant que la direction n\'a pas tranché', function (): void {
    $ids = seedCore();
    $token = tokenFor($ids['user_service_manager']);
    $shipmentId = $this->withToken($token)->postJson('/api/v1/shipments', waiverPayload($ids))->json('data.id');

    $this->withToken($token)->postJson("/api/v1/shipments/{$shipmentId}/advance", ['next_step' => 'booking'])
        ->assertStatus(422)->assertJsonPath('error_code', 'shipment.quote_waiver_pending');
});

it('réserve la décision au directeur, la refuse à l\'exploitant', function (): void {
    $ids = seedCore();
    $shipmentId = $this->withToken(tokenFor($ids['user_service_manager']))
        ->postJson('/api/v1/shipments', waiverPayload($ids))->json('data.id');

    // L'agent qui a demandé ne peut pas s'auto-valider.
    $this->withToken(tokenFor($ids['user_transit_agent']))
        ->postJson("/api/v1/shipments/{$shipmentId}/waiver/decide", ['decision' => 'approved'])
        ->assertForbidden();

    $this->withToken(tokenFor($ids['user_transit_agent']))
        ->getJson('/api/v1/shipments/waivers')->assertForbidden();
});

it('débloque le dossier une fois la direction favorable', function (): void {
    $ids = seedCore();
    $shipmentId = $this->withToken(tokenFor($ids['user_service_manager']))
        ->postJson('/api/v1/shipments', waiverPayload($ids))->json('data.id');

    freshAuth();
    $this->withToken(tokenFor($ids['user_director']))
        ->postJson("/api/v1/shipments/{$shipmentId}/waiver/decide", ['decision' => 'approved'])
        ->assertOk()->assertJsonPath('status', 'approved');

    $row = DB::table('shipments')->where('id', $shipmentId)->first(['quote_waiver_status', 'quote_waiver_decided_by']);
    expect($row->quote_waiver_status)->toBe('approved')
        ->and($row->quote_waiver_decided_by)->toBe($ids['user_director']);

    // Le dossier reprend son cours.
    freshAuth();
    // La dérogation ne bloque plus : l'avancement ne bute que sur les règles
    // ordinaires du workflow, ici le document exigé à l'étape suivante.
    $advance = $this->withToken(tokenFor($ids['user_transit_agent']))
        ->postJson("/api/v1/shipments/{$shipmentId}/advance", ['next_step' => 'booking']);

    expect($advance->json('error_code'))->not->toBe('shipment.quote_waiver_pending');
});

it('laisse le dossier bloqué après un refus, motif à l\'appui', function (): void {
    $ids = seedCore();
    $token = tokenFor($ids['user_service_manager']);
    $shipmentId = $this->withToken($token)->postJson('/api/v1/shipments', waiverPayload($ids))->json('data.id');

    // Un refus s'explique : l'exploitant doit savoir quoi corriger.
    freshAuth();
    $this->withToken(tokenFor($ids['user_director']))
        ->postJson("/api/v1/shipments/{$shipmentId}/waiver/decide", ['decision' => 'rejected'])
        ->assertStatus(422);

    freshAuth();
    $this->withToken(tokenFor($ids['user_director']))
        ->postJson("/api/v1/shipments/{$shipmentId}/waiver/decide", [
            'decision' => 'rejected', 'note' => 'Client sous encours dépassé — cotation et acompte requis.',
        ])->assertOk();

    freshAuth();
    $this->withToken($token)->postJson("/api/v1/shipments/{$shipmentId}/advance", ['next_step' => 'booking'])
        ->assertStatus(422)->assertJsonPath('detail', fn (string $d) => str_contains($d, 'refusée'));
});

it('présente à la direction la file des demandes en attente', function (): void {
    $ids = seedCore();
    $this->withToken(tokenFor($ids['user_service_manager']))->postJson('/api/v1/shipments', waiverPayload($ids));

    freshAuth();
    $queue = $this->withToken(tokenFor($ids['user_director']))
        ->getJson('/api/v1/shipments/waivers')->assertOk()->json('data');

    expect($queue)->toHaveCount(1)
        ->and($queue[0]['quote_waiver_reason'])->toContain('déjà à quai')
        ->and($queue[0]['requested_by'])->not->toBe('—');
});

it('ne valide pas deux fois la même demande', function (): void {
    $ids = seedCore();
    $shipmentId = $this->withToken(tokenFor($ids['user_service_manager']))
        ->postJson('/api/v1/shipments', waiverPayload($ids))->json('data.id');
    freshAuth();
    $director = tokenFor($ids['user_director']);

    $this->withToken($director)->postJson("/api/v1/shipments/{$shipmentId}/waiver/decide", ['decision' => 'approved'])->assertOk();
    $this->withToken($director)->postJson("/api/v1/shipments/{$shipmentId}/waiver/decide", ['decision' => 'rejected', 'note' => 'Changement d\'avis'])
        ->assertStatus(422);
});

it('n\'a rien à valider sur un dossier appuyé par une cotation', function (): void {
    $ids = seedCore();
    $shipmentId = $this->withToken(tokenFor($ids['user_service_manager']))->postJson('/api/v1/shipments', [
        'client_id' => $ids['client'], 'branch_id' => $ids['branch'], 'company_id' => $ids['company'],
        'agent_id' => $ids['user_transit_agent'], 'quote_id' => seedAcceptedQuote($ids),
    ])->json('data.id');

    freshAuth();
    $this->withToken(tokenFor($ids['user_director']))
        ->postJson("/api/v1/shipments/{$shipmentId}/waiver/decide", ['decision' => 'approved'])
        ->assertStatus(422)->assertJsonPath('message', fn (string $m) => str_contains($m, 'repose sur une cotation'));
});
