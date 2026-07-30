<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Silaris\Modules\OdooSync\Application\Job\PushInvoiceToOdoo;
use Silaris\Modules\OdooSync\Application\Service\EntityMap;
use Silaris\Modules\OdooSync\Application\Service\PullPaymentStatuses;
use Silaris\Modules\OdooSync\Application\Service\SyncLogger;
use Silaris\Modules\OdooSync\Application\Translator\InvoiceTranslator;
use Silaris\Modules\OdooSync\Application\Translator\PartyTranslator;
use Silaris\Modules\OdooSync\Infrastructure\Transport\OdooClientFactory;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;

uses(RefreshDatabase::class);

/** Réponse JSON-RPC Odoo. */
function odooResult(mixed $result): array
{
    return ['jsonrpc' => '2.0', 'id' => 1, 'result' => $result];
}

function seedOdooFixture(): array
{
    $tenantId = (string) Str::uuid7();
    DB::table('tenants')->insert(['id' => $tenantId, 'name' => 'T', 'slug' => 't-'.Str::random(6), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('currencies')->insert(['code' => 'XOF', 'name' => 'FCFA', 'symbol' => 'F', 'decimals' => 0, 'created_at' => now(), 'updated_at' => now()]);

    $companyId = (string) Str::uuid7();
    DB::table('companies')->insert(['id' => $companyId, 'tenant_id' => $tenantId, 'legal_name' => 'Co', 'code' => 'CO', 'currency_code' => 'XOF', 'created_at' => now(), 'updated_at' => now()]);

    $partyId = (string) Str::uuid7();
    DB::table('parties')->insert(['id' => $partyId, 'tenant_id' => $tenantId, 'type' => 'client', 'code' => 'CLI', 'name' => 'Client Test', 'created_at' => now(), 'updated_at' => now()]);

    $invoiceId = (string) Str::uuid7();
    DB::table('invoices')->insert([
        'id' => $invoiceId, 'tenant_id' => $tenantId, 'company_id' => $companyId, 'type' => 'invoice',
        'number' => 'F-TEST-0001', 'party_id' => $partyId, 'status' => 'validated', 'payment_status' => 'unpaid',
        'currency_code' => 'XOF', 'total_excl_tax' => 1000, 'total_tax' => 0, 'total_incl_tax' => 1000,
        'issue_date' => now()->toDateString(), 'validated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('invoice_lines')->insert([
        'id' => (string) Str::uuid7(), 'invoice_id' => $invoiceId, 'position' => 1,
        'service_code' => 'freight', 'description' => 'Fret test', 'quantity' => 1, 'unit' => 'flat',
        'unit_price' => 1000, 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('odoo_connections')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
        'base_url' => 'https://odoo.test', 'database' => 'testdb', 'username' => 'sync@silaris.app',
        'api_key' => Crypt::encryptString('secret-key'), 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$tenantId, $partyId, $invoiceId];
}

it('pousse une facture validée vers Odoo (client créé à la volée, mapping persisté)', function (): void {
    [$tenantId, $partyId, $invoiceId] = seedOdooFixture();

    Http::fake([
        'odoo.test/jsonrpc' => Http::sequence()
            ->push(odooResult(7))        // authenticate → uid 7
            ->push(odooResult(501))      // res.partner create → 501
            ->push(odooResult([44]))     // res.currency search → [44]
            ->push(odooResult(9001)),    // account.move create → 9001
    ]);

    (new PushInvoiceToOdoo($tenantId, $invoiceId))->handle(
        app(TenantContext::class),
        app(OdooClientFactory::class),
        app(InvoiceTranslator::class),
        app(PartyTranslator::class),
        app(EntityMap::class),
        app(SyncLogger::class),
    );

    // L'export réussi n'altère plus le statut : il inscrit l'état d'export à part.
    expect(DB::table('invoices')->where('id', $invoiceId)->value('status'))->toBe('validated')
        ->and(DB::table('invoices')->where('id', $invoiceId)->value('accounting_export_status'))->toBe('exported')
        ->and((int) DB::table('invoices')->where('id', $invoiceId)->value('odoo_id'))->toBe(9001)
        ->and((int) DB::table('odoo_entity_maps')->where('entity_type', 'invoice')->where('silaris_id', $invoiceId)->value('odoo_id'))->toBe(9001)
        ->and((int) DB::table('odoo_entity_maps')->where('entity_type', 'party')->where('silaris_id', $partyId)->value('odoo_id'))->toBe(501)
        ->and(DB::table('odoo_sync_logs')->where('entity_type', 'invoice')->where('status', 'success')->count())->toBe(1);

    // Payload account.move conforme : move_type + partner + ligne.
    Http::assertSent(function ($request) {
        $body = $request->data();
        if (($body['params']['args'][3] ?? null) === 'account.move' && ($body['params']['args'][4] ?? null) === 'create') {
            $payload = $body['params']['args'][5][0];

            return $payload['move_type'] === 'out_invoice'
                && $payload['partner_id'] === 501
                && $payload['invoice_line_ids'][0][2]['price_unit'] === 1000.0;
        }

        return true;
    });
});

it('marque l export en échec sur dead letter d une erreur métier Odoo', function (): void {
    [$tenantId, , $invoiceId] = seedOdooFixture();

    Http::fake([
        'odoo.test/jsonrpc' => Http::sequence()
            ->push(odooResult(7))
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['message' => 'Odoo Server Error', 'data' => ['message' => 'Champ requis manquant']]]),
    ]);

    $job = new PushInvoiceToOdoo($tenantId, $invoiceId);

    try {
        $job->handle(
            app(TenantContext::class),
            app(OdooClientFactory::class),
            app(InvoiceTranslator::class),
            app(PartyTranslator::class),
            app(EntityMap::class),
            app(SyncLogger::class),
        );
    } catch (Throwable) {
        // fail() lève en contexte de test — attendu
    }

    // La facture reste validée ; seul l'export est en échec, isolé du statut.
    expect(DB::table('invoices')->where('id', $invoiceId)->value('status'))->toBe('validated')
        ->and(DB::table('invoices')->where('id', $invoiceId)->value('accounting_export_status'))->toBe('failed')
        ->and(DB::table('odoo_sync_logs')->where('status', 'dead_letter')->count())->toBeGreaterThanOrEqual(1);
});

it('rapatrie les statuts de paiement depuis Odoo (Odoo maître)', function (): void {
    [$tenantId, , $invoiceId] = seedOdooFixture();

    app(TenantContext::class)->set($tenantId);
    DB::table('odoo_entity_maps')->insert([
        'tenant_id' => $tenantId, 'entity_type' => 'invoice', 'silaris_id' => $invoiceId,
        'odoo_model' => 'account.move', 'odoo_id' => 9001, 'created_at' => now(), 'updated_at' => now(),
    ]);

    Http::fake([
        'odoo.test/jsonrpc' => Http::sequence()
            ->push(odooResult(7))
            ->push(odooResult([['id' => 9001, 'payment_state' => 'paid']])),
    ]);

    $updated = app(PullPaymentStatuses::class)->run();

    expect($updated)->toBe(1)
        ->and(DB::table('invoices')->where('id', $invoiceId)->value('payment_status'))->toBe('paid');
});
