<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Silaris\Modules\Tenancy\Infrastructure\Persistence\Model\CompanyModel;

uses(RefreshDatabase::class);

/** Active la FNE sur la société : identifiants d'enrôlement + clé chiffrée. */
function enableFne(array $ids): void
{
    CompanyModel::where('id', $ids['company'])->first()->update([
        'fne_settings' => ['ncc' => '9606123X', 'point_of_sale' => 'PDV01', 'establishment' => 'SIEGE', 'enabled' => true],
        'fne_api_key' => 'test-key-xyz',
    ]);
}

/** Facture validée du montant voulu, dans la devise voulue, prête à certifier. */
function seedValidatedInvoiceFne(array $ids, string $currency = 'XOF'): string
{
    // Les fixtures ne sèment que le franc CFA ; toute autre devise doit exister
    // au référentiel avant d'être référencée par la facture.
    DB::table('currencies')->insertOrIgnore([
        ['code' => $currency, 'name' => $currency, 'symbol' => $currency, 'decimals' => 2, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $id = (string) Str::uuid7();
    DB::table('invoices')->insert([
        'id' => $id, 'tenant_id' => $ids['tenant'], 'company_id' => $ids['company'], 'type' => 'invoice',
        'number' => 'F-'.date('Y').'-'.substr($id, 0, 4), 'party_id' => $ids['client'], 'status' => 'validated',
        'payment_status' => 'unpaid', 'currency_code' => $currency,
        'total_excl_tax' => 1_000_000, 'total_tax' => 0, 'total_incl_tax' => 1_000_000,
        'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        'validated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('invoice_lines')->insert([
        'id' => (string) Str::uuid7(), 'invoice_id' => $id, 'position' => 1, 'service_code' => 'FREIGHT',
        'description' => 'Fret maritime', 'quantity' => 1, 'unit' => 'container', 'unit_price' => 1_000_000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function fakeDgi(): void
{
    Http::fake(['*/external/invoices/sign' => Http::response([
        'reference' => 'FNE-2026-000123', 'token' => 'https://verif.dgi.gouv.ci/t/abc123', 'balance_sticker' => 4987,
    ], 200)]);
}

it('certifie une facture B2C et inscrit le sceau de la DGI', function (): void {
    $ids = seedCore();
    enableFne($ids);
    fakeDgi();
    $invoiceId = seedValidatedInvoiceFne($ids);

    $this->withToken(tokenFor($ids['user_accountant']))
        ->postJson("/api/v1/invoices/{$invoiceId}/fne-certify")
        ->assertOk()
        ->assertJsonPath('fne_reference', 'FNE-2026-000123')
        ->assertJsonPath('fne_template', 'B2C');

    $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
    expect($invoice->fne_token)->toBe('https://verif.dgi.gouv.ci/t/abc123')
        ->and($invoice->fne_balance_sticker)->toBe(4987)
        ->and($invoice->fne_certified_at)->not->toBeNull();

    // Le NCC client absent : la charge utile ne le porte pas (B2C).
    Http::assertSent(fn ($request) => $request['template'] === 'B2C' && ! isset($request['clientNcc']));
});

it('passe en B2B et transmet le NCC quand le client en a un', function (): void {
    $ids = seedCore();
    enableFne($ids);
    fakeDgi();
    DB::table('parties')->where('id', $ids['client'])->update(['ncc' => '0512345Z']);
    $invoiceId = seedValidatedInvoiceFne($ids);

    $this->withToken(tokenFor($ids['user_accountant']))
        ->postJson("/api/v1/invoices/{$invoiceId}/fne-certify")
        ->assertOk()->assertJsonPath('fne_template', 'B2B');

    Http::assertSent(fn ($request) => $request['template'] === 'B2B' && $request['clientNcc'] === '0512345Z');
});

it('exige le taux de change en devise étrangère (B2F)', function (): void {
    $ids = seedCore();
    enableFne($ids);
    fakeDgi();
    $invoiceId = seedValidatedInvoiceFne($ids, 'EUR');
    $token = tokenFor($ids['user_accountant']);

    // Sans taux : refus net, aucun appel à la DGI.
    $this->withToken($token)->postJson("/api/v1/invoices/{$invoiceId}/fne-certify")
        ->assertStatus(422)->assertJsonPath('error_code', 'fne.foreign_rate_required');
    Http::assertNothingSent();

    // Avec taux : la devise et le taux partent à la DGI.
    freshAuth();
    $this->withToken($token)->postJson("/api/v1/invoices/{$invoiceId}/fne-certify", ['foreign_currency_rate' => 655.957])
        ->assertOk()->assertJsonPath('fne_template', 'B2F');
    Http::assertSent(fn ($request) => $request['foreignCurrency'] === 'EUR' && $request['foreignCurrencyRate'] === 655.957);
});

it('refuse de certifier un brouillon', function (): void {
    $ids = seedCore();
    enableFne($ids);
    $id = (string) Str::uuid7();
    DB::table('invoices')->insert([
        'id' => $id, 'tenant_id' => $ids['tenant'], 'company_id' => $ids['company'], 'type' => 'invoice',
        'party_id' => $ids['client'], 'status' => 'draft', 'payment_status' => 'none', 'currency_code' => 'XOF',
        'total_excl_tax' => 0, 'total_tax' => 0, 'total_incl_tax' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->withToken(tokenFor($ids['user_accountant']))
        ->postJson("/api/v1/invoices/{$id}/fne-certify")
        ->assertStatus(422)->assertJsonPath('error_code', 'fne.invoice_not_validated');
});

it('refuse quand la société n est pas configurée pour la FNE', function (): void {
    $ids = seedCore();
    $invoiceId = seedValidatedInvoiceFne($ids); // FNE non activée

    $this->withToken(tokenFor($ids['user_accountant']))
        ->postJson("/api/v1/invoices/{$invoiceId}/fne-certify")
        ->assertStatus(422)->assertJsonPath('error_code', 'fne.not_configured');
});

it('remonte le refus de la DGI sans consommer de certification', function (): void {
    $ids = seedCore();
    enableFne($ids);
    Http::fake(['*/external/invoices/sign' => Http::response(['message' => 'NCC invalide'], 400)]);
    $invoiceId = seedValidatedInvoiceFne($ids);

    $this->withToken(tokenFor($ids['user_accountant']))
        ->postJson("/api/v1/invoices/{$invoiceId}/fne-certify")
        ->assertStatus(422)->assertJsonPath('error_code', 'fne.rejected');

    expect(DB::table('invoices')->where('id', $invoiceId)->value('fne_reference'))->toBeNull();
});

it('ne certifie pas deux fois la même facture', function (): void {
    $ids = seedCore();
    enableFne($ids);
    fakeDgi();
    $invoiceId = seedValidatedInvoiceFne($ids);
    $token = tokenFor($ids['user_accountant']);

    $this->withToken($token)->postJson("/api/v1/invoices/{$invoiceId}/fne-certify")->assertOk();
    freshAuth();
    $this->withToken($token)->postJson("/api/v1/invoices/{$invoiceId}/fne-certify")
        ->assertStatus(422)->assertJsonPath('error_code', 'fne.already_certified');
});

it('interdit la certification à un rôle sans le droit', function (): void {
    $ids = seedCore();
    enableFne($ids);
    $invoiceId = seedValidatedInvoiceFne($ids);

    // L'agent transit tient les dossiers, il ne certifie pas les factures.
    $this->withToken(tokenFor($ids['user_transit_agent']))
        ->postJson("/api/v1/invoices/{$invoiceId}/fne-certify")
        ->assertForbidden();
});
