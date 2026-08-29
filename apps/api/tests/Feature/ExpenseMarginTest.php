<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// seedDossierWithForecast() vit dans tests/Pest.php — partagé avec le rapport
// d'efficacité par agent.

it('dégage la marge réelle face à la prévisionnelle une fois les dépenses validées', function (): void {
    $ids = seedCore();
    $shipmentId = seedDossierWithForecast($ids);
    $token = tokenFor($ids['user_finance_manager']);

    // Une dépense fournisseur de 200 000, enregistrée puis validée.
    $expense = $this->withToken($token)->postJson("/api/v1/shipments/{$shipmentId}/expenses", [
        'label' => 'Acconage', 'amount' => 200_000, 'currency_code' => 'XOF',
    ])->assertCreated()->json();

    // Tant qu'elle n'est pas validée, elle ne pèse pas dans le coût réel.
    $before = $this->withToken($token)->getJson("/api/v1/shipments/{$shipmentId}/expenses")->assertOk()->json('margin');
    expect((float) $before['real']['cost'])->toBe(0.0)
        ->and((float) $before['real']['pending_cost'])->toBe(200_000.0)
        ->and((float) $before['forecast']['margin'])->toBe(300_000.0);

    $this->withToken($token)->postJson("/api/v1/expenses/{$expense['id']}/validate", ['status' => 'validated'])->assertOk();

    $m = $this->withToken($token)->getJson("/api/v1/shipments/{$shipmentId}/expenses")->assertOk()->json('margin');
    // Réel : CA 1 200 000 − coût 200 000 = 1 000 000 ; écart vs prévu +700 000.
    expect((float) $m['real']['revenue'])->toBe(1_200_000.0)
        ->and((float) $m['real']['cost'])->toBe(200_000.0)
        ->and((float) $m['real']['margin'])->toBe(1_000_000.0)
        ->and((float) $m['variance'])->toBe(700_000.0);
});

it('refuse la saisie d\'une dépense à un rôle sans expenses.create', function (): void {
    $ids = seedCore();
    $shipmentId = seedDossierWithForecast($ids);

    $this->withToken(tokenFor($ids['user_driver']))
        ->postJson("/api/v1/shipments/{$shipmentId}/expenses", ['label' => 'X', 'amount' => 1000, 'currency_code' => 'XOF'])
        ->assertForbidden();
});

it('rattache un fournisseur à la dépense et la liste globalement', function (): void {
    $ids = seedCore();
    $shipmentId = seedDossierWithForecast($ids);
    $token = tokenFor($ids['user_finance_manager']);

    $supplierId = (string) Str::uuid7();
    DB::table('parties')->insert([
        'id' => $supplierId, 'tenant_id' => $ids['tenant'], 'type' => 'supplier', 'supplier_kind' => 'trucker',
        'code' => 'FOU-0001', 'name' => 'Transporteur SARL', 'payment_terms_days' => 30,
        'notification_prefs' => '{}', 'tags' => '[]', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->withToken($token)->postJson("/api/v1/shipments/{$shipmentId}/expenses", [
        'label' => 'Transport', 'amount' => 150_000, 'currency_code' => 'XOF', 'supplier_id' => $supplierId,
    ])->assertCreated()->assertJsonPath('supplier.name', 'Transporteur SARL');

    // Endpoint global : la dépense remonte avec son dossier et son fournisseur.
    $row = collect($this->withToken($token)->getJson('/api/v1/expenses')->assertOk()->json('data'))
        ->firstWhere('label', 'Transport');
    expect($row['shipment']['reference'])->toBe('IMP-EXP-0001')
        ->and($row['supplier']['name'])->toBe('Transporteur SARL');
});

it('enregistre plusieurs dépenses en un seul envoi (lignes)', function (): void {
    $ids = seedCore();
    $shipmentId = seedDossierWithForecast($ids);
    $token = tokenFor($ids['user_finance_manager']);

    $this->withToken($token)->postJson("/api/v1/shipments/{$shipmentId}/expenses", [
        'lines' => [
            ['label' => 'Acconage', 'amount' => 100_000, 'currency_code' => 'XOF'],
            ['label' => 'Transport', 'amount' => 50_000, 'currency_code' => 'XOF'],
        ],
    ])->assertCreated()->assertJsonCount(2, 'data');

    $rows = $this->withToken($token)->getJson("/api/v1/shipments/{$shipmentId}/expenses")->assertOk()->json('data');
    expect($rows)->toHaveCount(2);
});
