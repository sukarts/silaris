<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Silaris\Modules\Shipment\Application\Service\StandardWorkflowInstaller;

uses(RefreshDatabase::class);

/** Charge utile minimale d'un dossier, sans les identifiants d'organisation. */
function shipmentPayload(array $ids, array $overrides = []): array
{
    return [
        'client_id' => $ids['client'], 'branch_id' => $ids['branch'], 'company_id' => $ids['company'],
        'agent_id' => $ids['user_transit_agent'],
        // Le dossier n'existe que sur cotation acceptée : le payload en porte une.
        'quote_id' => seedAcceptedQuote($ids),
        ...$overrides,
    ];
}

it('donne à l\'agent transit son périmètre de saisie sans droit d\'administration', function (): void {
    $ids = seedCore();
    $token = tokenFor($ids['user_service_manager']);

    // L'agent n'a pas accès aux paramètres de la société…
    $this->withToken($token)->getJson('/api/v1/admin/companies')->assertForbidden();

    // …mais son agence de rattachement lui indique société et agence.
    $branches = $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->json('branches');

    expect($branches)->toHaveCount(1)
        ->and($branches[0]['id'])->toBe($ids['branch'])
        ->and($branches[0]['company_id'])->toBe($ids['company'])
        ->and($branches[0]['company_name'])->toBe('Test SA');
});

it('laisse l\'agent transit créer un dossier', function (): void {
    $ids = seedCore();

    $reference = $this->withToken(tokenFor($ids['user_service_manager']))
        ->postJson('/api/v1/shipments', shipmentPayload($ids))
        ->assertCreated()->json('data.reference');

    expect($reference)->not->toBeEmpty();
});

it('explique quoi faire quand le tenant n\'a aucun workflow', function (): void {
    $ids = seedCore();
    // Un tenant provisionné avant l'installation automatique se trouve dans cet
    // état : la création échouait alors sans message exploitable.
    DB::table('workflow_steps')->delete();
    DB::table('workflow_definitions')->where('tenant_id', $ids['tenant'])->delete();

    $this->withToken(tokenFor($ids['user_admin']))
        ->postJson('/api/v1/shipments', shipmentPayload($ids))
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'workflow.none_available');
});

it('installe le workflow standard pour un tenant qui en manque', function (): void {
    $ids = seedCore();
    DB::table('workflow_steps')->delete();
    DB::table('workflow_definitions')->where('tenant_id', $ids['tenant'])->delete();

    $workflowId = app(StandardWorkflowInstaller::class)->installFor($ids['tenant']);

    expect($workflowId)->not->toBeNull()
        ->and(DB::table('workflow_steps')->where('workflow_definition_id', $workflowId)->count())->toBe(8);

    $reference = $this->withToken(tokenFor($ids['user_admin']))
        ->postJson('/api/v1/shipments', shipmentPayload($ids))
        ->assertCreated()->json('data.reference');
    expect($reference)->not->toBeEmpty();
});

it('ne double pas le workflow d\'un tenant déjà servi', function (): void {
    $ids = seedCore();
    $before = DB::table('workflow_definitions')->where('tenant_id', $ids['tenant'])->count();

    expect(app(StandardWorkflowInstaller::class)->installFor($ids['tenant']))->toBeNull()
        ->and(DB::table('workflow_definitions')->where('tenant_id', $ids['tenant'])->count())->toBe($before);
});
