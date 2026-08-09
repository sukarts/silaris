<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('mesure la marge réelle vs prévue par agent de transit', function (): void {
    $ids = seedCore();
    // seedDossierWithForecast (tests/Feature/ExpenseMarginTest.php) : dossier de
    // l'agent transit, cotation vente 1 000 000 / coût 700 000, facture 1 200 000.
    $shipmentId = seedDossierWithForecast($ids);

    // Une dépense validée de 200 000 sur le dossier.
    DB::table('expenses')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $ids['tenant'], 'company_id' => $ids['company'],
        'shipment_id' => $shipmentId, 'label' => 'Acconage', 'amount' => 200_000, 'currency_code' => 'XOF',
        'status' => 'validated', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $agents = collect($this->withToken(tokenFor($ids['user_admin']))
        ->getJson('/api/v1/reports/agents')->assertOk()->json('agents'));

    $row = $agents->firstWhere('agent_id', $ids['user_transit_agent']);
    expect($row)->not->toBeNull()
        ->and((float) $row['forecast_margin'])->toBe(300_000.0)   // 1 000 000 − 700 000
        ->and((float) $row['real_margin'])->toBe(1_000_000.0)     // 1 200 000 − 200 000
        ->and((float) $row['variance'])->toBe(700_000.0)
        ->and($row['dossiers'])->toBeGreaterThanOrEqual(1);
});

it('refuse le rapport agents à un rôle sans reports.read', function (): void {
    $ids = seedCore();

    $this->withToken(tokenFor($ids['user_driver']))->getJson('/api/v1/reports/agents')->assertForbidden();
});
