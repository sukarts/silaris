<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** Cotation acceptée avec deux lignes, prête à être facturée. */
function seedAcceptedQuoteWithLines(array $ids): string
{
    $quoteId = seedAcceptedQuote($ids, overrides: ['total_amount' => 800_000]);
    foreach ([['DD', 'Droit de douane', 'customs', 1, 'flat', 500_000], ['LIVRAISON', 'Livraison', 'other', 2, 'flat', 150_000]] as $i => [$sc, $d, $cat, $q, $un, $pu]) {
        DB::table('quote_lines')->insert([
            'id' => (string) Str::uuid7(), 'quote_id' => $quoteId, 'position' => $i + 1,
            'service_code' => $sc, 'description' => $d, 'category' => $cat, 'quantity' => $q,
            'unit' => $un, 'unit_price' => $pu, 'currency_code' => 'XOF',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $quoteId;
}

it('déverse une cotation acceptée dans un brouillon de facture, lignes à l identique', function (): void {
    Queue::fake();
    $ids = seedCore();
    $quoteId = seedAcceptedQuoteWithLines($ids);

    $response = $this->withToken(tokenFor($ids['user_finance_manager']))
        ->postJson("/api/v1/invoices/from-quote/{$quoteId}");

    $response->assertCreated()
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('type', 'invoice')
        ->assertJsonPath('quote_id', $quoteId);

    $invoiceId = $response->json('id');
    expect(DB::table('invoice_lines')->where('invoice_id', $invoiceId)->count())->toBe(2)
        ->and((float) DB::table('invoices')->where('id', $invoiceId)->value('total_excl_tax'))->toBe(800_000.0)
        ->and(DB::table('invoice_lines')->where('invoice_id', $invoiceId)->where('description', 'Droit de douane')->exists())->toBeTrue();
});

it('rattache la facture au dossier ouvert sur la cotation', function (): void {
    Queue::fake();
    $ids = seedCore();
    $quoteId = seedAcceptedQuoteWithLines($ids);

    // Un dossier existe sur cette cotation : la facture doit s'y rattacher.
    $shipmentId = $this->withToken(tokenFor($ids['user_service_manager']))->postJson('/api/v1/shipments', [
        'client_id' => $ids['client'], 'branch_id' => $ids['branch'], 'company_id' => $ids['company'],
        'agent_id' => $ids['user_transit_agent'], 'quote_id' => $quoteId,
    ])->json('data.id');

    freshAuth();
    $invoiceId = $this->withToken(tokenFor($ids['user_finance_manager']))
        ->postJson("/api/v1/invoices/from-quote/{$quoteId}")->assertCreated()->json('id');

    expect(DB::table('invoices')->where('id', $invoiceId)->value('shipment_id'))->toBe($shipmentId);
});

it('refuse de facturer une cotation non acceptée', function (): void {
    Queue::fake();
    $ids = seedCore();
    $quoteId = seedAcceptedQuote($ids, overrides: ['status' => 'draft', 'accepted_at' => null]);

    $this->withToken(tokenFor($ids['user_finance_manager']))
        ->postJson("/api/v1/invoices/from-quote/{$quoteId}")
        ->assertNotFound();
});

it('expose le barème de TVA actif', function (): void {
    $ids = seedCore();
    $taxId = (string) Str::uuid7();
    DB::table('tax_rates')->insert([
        'id' => $taxId, 'tenant_id' => $ids['tenant'], 'name' => 'TVA CI 18 %', 'rate_percent' => 18,
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('tax_rates')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $ids['tenant'], 'name' => 'Ancien taux', 'rate_percent' => 20,
        'is_active' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->withToken(tokenFor($ids['user_accountant']))
        ->getJson('/api/v1/tax-rates')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.name', 'TVA CI 18 %');
});
