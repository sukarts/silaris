<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function seedInvoiceWithLine(array $ids): string
{
    $invoiceId = (string) Str::uuid7();
    DB::table('invoices')->insert([
        'id' => $invoiceId, 'tenant_id' => $ids['tenant'], 'company_id' => $ids['company'],
        'type' => 'invoice', 'number' => 'F-2026-0001', 'party_id' => $ids['client'],
        'status' => 'validated', 'currency_code' => 'XOF',
        'total_excl_tax' => 100000, 'total_tax' => 18000, 'total_incl_tax' => 118000,
        'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('invoice_lines')->insert([
        'id' => (string) Str::uuid7(), 'invoice_id' => $invoiceId, 'position' => 1,
        'service_code' => 'FRT', 'description' => 'Fret maritime test', 'quantity' => 1,
        'unit' => 'flat', 'unit_price' => 100000, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $invoiceId;
}

it('génère le PDF d\'une facture avec le bon nom de fichier', function (): void {
    $ids = seedCore();
    $invoiceId = seedInvoiceWithLine($ids);

    $response = $this->withToken(tokenFor($ids['user_admin']))->get("/api/v1/invoices/{$invoiceId}/pdf");

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertDownload('facture-F-2026-0001.pdf');
    expect(str_starts_with((string) $response->getContent(), '%PDF'))->toBeTrue();
});

it('génère le PDF d\'une cotation', function (): void {
    $ids = seedCore();
    $quoteId = (string) Str::uuid7();
    DB::table('quotes')->insert([
        'id' => $quoteId, 'tenant_id' => $ids['tenant'], 'company_id' => $ids['company'],
        'number' => 'Q-2026-0001', 'party_id' => $ids['client'], 'owner_id' => $ids['user_admin'],
        'status' => 'draft', 'mode' => 'sea_fcl', 'direction' => 'import',
        'origin_locode' => 'CNSHA', 'destination_locode' => 'CIABJ', 'incoterm_code' => 'CIF',
        'currency_code' => 'XOF', 'total_amount' => 500000,
        'valid_until' => now()->addDays(30)->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('quote_lines')->insert([
        'id' => (string) Str::uuid7(), 'quote_id' => $quoteId, 'position' => 1,
        'service_code' => 'FRT', 'description' => 'Fret maritime', 'quantity' => 1,
        'unit' => 'flat', 'unit_price' => 500000, 'currency_code' => 'XOF',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->withToken(tokenFor($ids['user_admin']))->get("/api/v1/quotes/{$quoteId}/pdf")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertDownload('cotation-Q-2026-0001.pdf');
});

it('refuse le PDF facture à un rôle sans invoices.read', function (): void {
    $ids = seedCore();
    $invoiceId = seedInvoiceWithLine($ids);

    $this->withToken(tokenFor($ids['user_driver']))->get("/api/v1/invoices/{$invoiceId}/pdf")
        ->assertForbidden();
});
