<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function seedPortalInvoice(array $ids, string $partyId, string $number, float $incl, ?string $dueDate = null): string
{
    $id = (string) Str::uuid7();
    DB::table('invoices')->insert([
        'id' => $id, 'tenant_id' => $ids['tenant'], 'company_id' => $ids['company'],
        'type' => 'invoice', 'number' => $number, 'party_id' => $partyId,
        'status' => 'validated', 'currency_code' => 'XOF',
        'total_excl_tax' => $incl, 'total_tax' => 0, 'total_incl_tax' => $incl,
        'issue_date' => now()->subDays(45)->toDateString(),
        'due_date' => $dueDate ?? now()->subDays(40)->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function seedPortalPayment(array $ids, string $partyId, string $invoiceId, float $amount, string $ref): void
{
    $paymentId = (string) Str::uuid7();
    DB::table('payments')->insert([
        'id' => $paymentId, 'tenant_id' => $ids['tenant'], 'company_id' => $ids['company'],
        'party_id' => $partyId, 'reference' => $ref, 'method' => 'transfer',
        'currency_code' => 'XOF', 'amount' => $amount, 'received_on' => now()->subDays(5)->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('payment_allocations')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $ids['tenant'],
        'payment_id' => $paymentId, 'invoice_id' => $invoiceId, 'amount' => $amount,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('rend le relevé du client : reste dû, balance âgée et reçus', function (): void {
    $ids = seedCore();
    $partial = seedPortalInvoice($ids, $ids['client'], 'F-2026-0100', 100_000);
    seedPortalPayment($ids, $ids['client'], $partial, 40_000, 'REC-001');
    $paid = seedPortalInvoice($ids, $ids['client'], 'F-2026-0101', 50_000);
    seedPortalPayment($ids, $ids['client'], $paid, 50_000, 'REC-002');

    $body = $this->withToken(portalTokenFor($ids['portal']))
        ->getJson('/api/v1/portal/invoices')->assertOk()->json();

    $rows = collect($body['data']);
    $p = $rows->firstWhere('number', 'F-2026-0100');
    expect((float) $p['paid'])->toBe(40_000.0)
        ->and((float) $p['outstanding'])->toBe(60_000.0)
        ->and($p['pay_status'])->toBe('partial');

    $q = $rows->firstWhere('number', 'F-2026-0101');
    expect((float) $q['outstanding'])->toBe(0.0)
        ->and($q['pay_status'])->toBe('paid');

    // Balance âgée : seul le reste dû de la facture partielle (échéance à -40 j).
    expect((float) $body['summary']['total'])->toBe(60_000.0)
        ->and((float) $body['summary']['31_60'])->toBe(60_000.0);

    // Reçus : les deux règlements du client.
    expect(collect($body['receipts'])->pluck('reference')->all())->toContain('REC-001', 'REC-002');
});

it('ne montre jamais les factures d\'un autre client', function (): void {
    $ids = seedCore();
    seedPortalInvoice($ids, $ids['client'], 'F-MIENNE', 10_000);

    $otherParty = (string) Str::uuid7();
    DB::table('parties')->insert([
        'id' => $otherParty, 'tenant_id' => $ids['tenant'], 'type' => 'client', 'code' => 'CLI2',
        'name' => 'Autre Client', 'payment_terms_days' => 30, 'notification_prefs' => '{}', 'tags' => '[]',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    seedPortalInvoice($ids, $otherParty, 'F-AUTRUI', 999_000);

    $numbers = collect($this->withToken(portalTokenFor($ids['portal']))
        ->getJson('/api/v1/portal/invoices')->assertOk()->json('data'))->pluck('number');

    expect($numbers)->toContain('F-MIENNE')->not->toContain('F-AUTRUI');
});
