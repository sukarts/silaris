<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('crée un dossier via l API avec référence séquencée, puis bloque et débloque la transition selon les documents', function (): void {
    $ids = seedCore();
    $token = tokenFor($ids['user_service_manager']);

    // Création — sur cotation acceptée, seul point d'entrée d'un dossier.
    $create = $this->withToken($token)->postJson('/api/v1/shipments', [
        'client_id' => $ids['client'], 'branch_id' => $ids['branch'], 'company_id' => $ids['company'],
        'agent_id' => $ids['user_transit_agent'],
        'quote_id' => seedAcceptedQuote($ids),
    ]);
    $create->assertCreated();
    $shipmentId = $create->json('data.id');
    expect($create->json('data.reference'))->toBe('TST-'.date('Y').'-00001')
        ->and($create->json('data.status'))->toBe('creation');

    // Outbox alimentée
    expect(DB::table('outbox_events')->where('aggregate_id', $shipmentId)->where('event_type', 'shipment.created')->exists())->toBeTrue();

    // Avance bloquée : document requis absent
    freshAuth();
    $this->withToken($token)->postJson("/api/v1/shipments/{$shipmentId}/advance", ['next_step' => 'booking'])
        ->assertUnprocessable()
        ->assertJsonPath('error_code', 'shipment.missing_required_documents');

    // Document fourni → avance OK
    DB::table('documents')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $ids['tenant'], 'shipment_id' => $shipmentId, 'type' => 'commercial_invoice', 'title' => 'FC', 'visibility' => 'client', 'status' => 'received', 'created_at' => now(), 'updated_at' => now()]);
    freshAuth();
    $this->withToken($token)->postJson("/api/v1/shipments/{$shipmentId}/advance", ['next_step' => 'booking'])
        ->assertOk()
        ->assertJsonPath('data.status', 'booking');

    // Transition illégale
    freshAuth();
    $this->withToken($token)->postJson("/api/v1/shipments/{$shipmentId}/advance", ['next_step' => 'closure'])
        ->assertUnprocessable()
        ->assertJsonPath('error_code', 'shipment.invalid_workflow_transition');
});

it('applique le RBAC : comptable sans création, chauffeur sans lecture CRM', function (): void {
    $ids = seedCore();

    freshAuth();
    $this->withToken(tokenFor($ids['user_accountant']))->postJson('/api/v1/shipments', [])->assertForbidden();
    freshAuth();
    $this->withToken(tokenFor($ids['user_driver']))->getJson('/api/v1/parties')->assertForbidden();
    freshAuth();
    $this->withToken(tokenFor($ids['user_driver']))->getJson('/api/v1/fleet/trucks')->assertOk();
    freshAuth();
    $this->withoutToken()->getJson('/api/v1/shipments')->assertUnauthorized();
});
