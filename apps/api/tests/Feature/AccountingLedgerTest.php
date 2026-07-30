<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Silaris\Modules\Billing\Domain\Accounting\AccountingLedger;
use Silaris\Modules\Billing\Infrastructure\Accounting\NullLedger;
use Silaris\Modules\Billing\Infrastructure\Accounting\OdooLedger;
use Silaris\Modules\OdooSync\Application\Job\PushInvoiceToOdoo;

uses(RefreshDatabase::class);

function seedDraftInvoice(array $ids): string
{
    $token = tokenFor($ids['user_admin']);
    freshAuth();
    $id = test()->withToken($token)->postJson('/api/v1/invoices', [
        'company_id' => $ids['company'], 'type' => 'invoice', 'party_id' => $ids['client'], 'currency_code' => 'XOF',
        'lines' => [['service_code' => 'FREIGHT', 'description' => 'Fret', 'quantity' => 1, 'unit' => 'flat', 'unit_price' => 500000]],
    ])->json('id');

    return $id;
}

it('sans comptabilité branchée, la facture validée n attend aucun export', function (): void {
    config(['accounting.driver' => 'null']);
    Queue::fake();
    $ids = seedCore();
    $invoiceId = seedDraftInvoice($ids);

    freshAuth();
    test()->withToken(tokenFor($ids['user_admin']))->postJson("/api/v1/invoices/{$invoiceId}/validate")->assertOk();

    expect(DB::table('invoices')->where('id', $invoiceId)->value('status'))->toBe('validated')
        ->and(DB::table('invoices')->where('id', $invoiceId)->value('accounting_export_status'))->toBe('none');
    Queue::assertNotPushed(PushInvoiceToOdoo::class);
});

it('le connecteur se choisit par configuration', function (): void {
    config(['accounting.driver' => 'null']);
    expect(app(AccountingLedger::class))->toBeInstanceOf(NullLedger::class);

    config(['accounting.driver' => 'odoo']);
    app()->forgetInstance(AccountingLedger::class);
    expect(app(AccountingLedger::class))->toBeInstanceOf(OdooLedger::class);
});

it('le statut de la facture ne porte plus les états d export', function (): void {
    // La contrainte n'admet que draft et validated : un état d'export y est rejeté.
    $ids = seedCore();
    $invoiceId = seedDraftInvoice($ids);

    expect(fn () => DB::table('invoices')->where('id', $invoiceId)->update(['status' => 'synced']))
        ->toThrow(QueryException::class);
});
