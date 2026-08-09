<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** Dossier avec sa cotation acceptée (vente 1 000 000, coût estimé 700 000) et une facture. */
function seedDossierWithForecast(array $ids): string
{
    $quoteId = (string) Str::uuid7();
    DB::table('quotes')->insert([
        'id' => $quoteId, 'tenant_id' => $ids['tenant'], 'company_id' => $ids['company'],
        'number' => 'Q-2026-0500', 'party_id' => $ids['client'], 'owner_id' => $ids['user_admin'],
        'status' => 'accepted', 'mode' => 'sea_fcl', 'direction' => 'import',
        'origin_locode' => 'CNSHA', 'destination_locode' => 'CIABJ', 'incoterm_code' => 'CIF',
        'currency_code' => 'XOF', 'total_amount' => 1_000_000, 'total_buy_amount' => 700_000,
        'accepted_at' => now(), 'valid_until' => now()->addDays(30)->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $shipmentId = seedShipmentFor($ids, $ids['client'], 'IMP-EXP-0001');
    DB::table('shipments')->where('id', $shipmentId)->update(['quote_id' => $quoteId]);

    // Facture émise du dossier : 1 200 000 HT.
    DB::table('invoices')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $ids['tenant'], 'company_id' => $ids['company'],
        'type' => 'invoice', 'number' => 'F-2026-0500', 'party_id' => $ids['client'],
        'status' => 'validated', 'currency_code' => 'XOF',
        'total_excl_tax' => 1_200_000, 'total_tax' => 0, 'total_incl_tax' => 1_200_000,
        'issue_date' => now()->toDateString(), 'shipment_id' => $shipmentId,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $shipmentId;
}

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
