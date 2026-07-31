<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function seedInvoice(array $ids, string $number, string $type, string $status, float $excl, ?string $originId = null): string
{
    $id = (string) Str::uuid7();
    DB::table('invoices')->insert([
        'id' => $id, 'tenant_id' => $ids['tenant'], 'company_id' => $ids['company'],
        'type' => $type, 'number' => $status === 'draft' ? null : $number, 'party_id' => $ids['client'],
        'status' => $status, 'currency_code' => 'XOF',
        'total_excl_tax' => $excl, 'total_tax' => 0, 'total_incl_tax' => $excl,
        'issue_date' => $status === 'draft' ? null : now()->toDateString(),
        'original_invoice_id' => $originId,
        'credit_reason' => $type === 'credit_note' ? 'Geste commercial' : null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

it('agrège la marge des offres gagnées et le CA facturé', function (): void {
    $ids = seedCore();

    // Offres gagnées : maritime (marge 300k) + aérien (marge 100k).
    seedAcceptedQuote($ids, null, ['number' => 'Q-R-001', 'mode' => 'sea_fcl', 'total_amount' => 1_000_000, 'total_buy_amount' => 700_000]);
    seedAcceptedQuote($ids, null, ['number' => 'Q-R-002', 'mode' => 'air', 'total_amount' => 500_000, 'total_buy_amount' => 400_000]);
    // Un brouillon non accepté ne compte pas.
    seedAcceptedQuote($ids, null, ['number' => 'Q-R-003', 'status' => 'draft', 'accepted_at' => null, 'total_amount' => 9_000_000]);

    // CA : une facture validée, moins un avoir ; un brouillon ne compte pas.
    $inv = seedInvoice($ids, 'F-2026-0001', 'invoice', 'validated', 800_000);
    seedInvoice($ids, 'AV-2026-0001', 'credit_note', 'validated', 100_000, $inv);
    seedInvoice($ids, 'F-2026-0002', 'invoice', 'draft', 999_000);

    $body = $this->withToken(tokenFor($ids['user_admin']))
        ->getJson('/api/v1/reports/business')->assertOk()->json();

    // Le JSON rend 1500000 pour 1500000.0 : on compare les valeurs, pas leur type.
    $totals = $body['margin']['totals'];
    expect((float) $totals['revenue'])->toBe(1_500_000.0)
        ->and((float) $totals['cost'])->toBe(1_100_000.0)
        ->and((float) $totals['margin'])->toBe(400_000.0)
        ->and((int) $totals['won_count'])->toBe(2)
        // Taux = 400 000 / 1 500 000 = 26,7 %.
        ->and((float) $totals['rate'])->toBe(26.7);

    $sea = collect($body['margin']['by_mode'])->firstWhere('mode', 'sea_fcl');
    expect((float) $sea['revenue'])->toBe(1_000_000.0)
        ->and((float) $sea['cost'])->toBe(700_000.0)
        ->and((float) $sea['margin'])->toBe(300_000.0);

    // CA net = 800 000 − 100 000 (avoir).
    expect((float) $body['revenue']['total'])->toBe(700_000.0)
        ->and($body['revenue']['by_company'])->not->toBeEmpty();
});

it('ne retient que la période demandée', function (): void {
    $ids = seedCore();
    seedAcceptedQuote($ids, null, ['total_amount' => 1_000_000, 'total_buy_amount' => 600_000, 'accepted_at' => now()->subMonths(10)]);

    $body = $this->withToken(tokenFor($ids['user_admin']))
        ->getJson('/api/v1/reports/business?from='.now()->subMonth()->toDateString().'&to='.now()->toDateString())
        ->assertOk()->json();

    expect((int) $body['margin']['totals']['won_count'])->toBe(0)
        ->and((float) $body['margin']['totals']['revenue'])->toBe(0.0);
});

it('refuse les rapports à un rôle sans reports.read', function (): void {
    $ids = seedCore();

    $this->withToken(tokenFor($ids['user_driver']))->getJson('/api/v1/reports/business')
        ->assertForbidden();
});
