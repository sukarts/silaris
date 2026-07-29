<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/** Crée une facture validée du montant demandé, et rend son identifiant. */
function seedValidatedInvoice(array $ids, float $amount, ?string $dueDate = null, ?string $partyId = null): string
{
    $token = tokenFor($ids['user_admin']);

    freshAuth();
    $draft = test()->withToken($token)->postJson('/api/v1/invoices', [
        'company_id' => $ids['company'], 'type' => 'invoice',
        'party_id' => $partyId ?? $ids['client'], 'currency_code' => 'XOF',
        'lines' => [['service_code' => 'freight', 'description' => 'Fret', 'quantity' => 1, 'unit' => 'flat', 'unit_price' => $amount]],
    ]);
    $draft->assertCreated();
    $invoiceId = $draft->json('id');

    freshAuth();
    test()->withToken($token)->postJson("/api/v1/invoices/{$invoiceId}/validate")->assertOk();

    // L'échéance est calculée depuis les conditions du client ; la forcer permet
    // d'éprouver l'ancienneté sans attendre.
    if ($dueDate !== null) {
        DB::table('invoices')->where('id', $invoiceId)->update(['due_date' => $dueDate]);
    }

    return $invoiceId;
}

it('solde une facture, en déduit l état de paiement et numérote le reçu', function (): void {
    Queue::fake();
    $ids = seedCore();
    $invoiceId = seedValidatedInvoice($ids, 1_000_000);

    freshAuth();
    $payment = $this->withToken(tokenFor($ids['user_finance_manager']))->postJson('/api/v1/payments', [
        'company_id' => $ids['company'], 'party_id' => $ids['client'],
        'method' => 'mobile_money', 'method_reference' => 'OM-884213',
        'currency_code' => 'XOF', 'amount' => 1_000_000, 'received_on' => now()->toDateString(),
    ]);

    $payment->assertCreated();
    expect($payment->json('reference'))->toBe('REC-'.date('Y').'-0001')
        ->and($payment->json('allocations'))->toHaveCount(1);

    expect(DB::table('invoices')->where('id', $invoiceId)->value('payment_status'))->toBe('paid');
});

it('marque partiel un règlement inférieur à la facture', function (): void {
    Queue::fake();
    $ids = seedCore();
    $invoiceId = seedValidatedInvoice($ids, 1_000_000);

    freshAuth();
    $this->withToken(tokenFor($ids['user_finance_manager']))->postJson('/api/v1/payments', [
        'company_id' => $ids['company'], 'party_id' => $ids['client'], 'method' => 'cash',
        'currency_code' => 'XOF', 'amount' => 400_000, 'received_on' => now()->toDateString(),
    ])->assertCreated();

    expect(DB::table('invoices')->where('id', $invoiceId)->value('payment_status'))->toBe('partial');
});

it('impute au plus ancien quand aucune imputation n est donnée', function (): void {
    Queue::fake();
    $ids = seedCore();
    $ancienne = seedValidatedInvoice($ids, 300_000, '2026-05-01');
    $recente = seedValidatedInvoice($ids, 400_000, '2026-07-01');

    freshAuth();
    $this->withToken(tokenFor($ids['user_finance_manager']))->postJson('/api/v1/payments', [
        'company_id' => $ids['company'], 'party_id' => $ids['client'], 'method' => 'transfer',
        'currency_code' => 'XOF', 'amount' => 500_000, 'received_on' => now()->toDateString(),
    ])->assertCreated();

    // 300 000 soldent la plus ancienne, les 200 000 restants entament l'autre.
    expect(DB::table('invoices')->where('id', $ancienne)->value('payment_status'))->toBe('paid')
        ->and(DB::table('invoices')->where('id', $recente)->value('payment_status'))->toBe('partial');
});

it('refuse d imputer plus que la somme reçue', function (): void {
    Queue::fake();
    $ids = seedCore();
    $invoiceId = seedValidatedInvoice($ids, 1_000_000);

    freshAuth();
    $this->withToken(tokenFor($ids['user_finance_manager']))->postJson('/api/v1/payments', [
        'company_id' => $ids['company'], 'party_id' => $ids['client'], 'method' => 'cash',
        'currency_code' => 'XOF', 'amount' => 100_000, 'received_on' => now()->toDateString(),
        'allocations' => [['invoice_id' => $invoiceId, 'amount' => 500_000]],
    ])->assertStatus(422);

    expect(DB::table('payments')->count())->toBe(0)
        ->and(DB::table('invoices')->where('id', $invoiceId)->value('payment_status'))->toBe('unpaid');
});

it('refuse de payer une facture au-delà de son montant', function (): void {
    // Le cas traître : sans ce garde-fou, le client deviendrait créditeur sans
    // qu'aucun avoir n'existe, et la balance âgée mentirait.
    Queue::fake();
    $ids = seedCore();
    $invoiceId = seedValidatedInvoice($ids, 200_000);

    freshAuth();
    $this->withToken(tokenFor($ids['user_finance_manager']))->postJson('/api/v1/payments', [
        'company_id' => $ids['company'], 'party_id' => $ids['client'], 'method' => 'cash',
        'currency_code' => 'XOF', 'amount' => 500_000, 'received_on' => now()->toDateString(),
        'allocations' => [['invoice_id' => $invoiceId, 'amount' => 500_000]],
    ])->assertStatus(422);
});

it('refuse un règlement dans une autre devise que la facture', function (): void {
    Queue::fake();
    $ids = seedCore();
    $invoiceId = seedValidatedInvoice($ids, 1_000_000);

    freshAuth();
    $this->withToken(tokenFor($ids['user_finance_manager']))->postJson('/api/v1/payments', [
        'company_id' => $ids['company'], 'party_id' => $ids['client'], 'method' => 'transfer',
        'currency_code' => 'EUR', 'amount' => 1_500, 'received_on' => now()->toDateString(),
        'allocations' => [['invoice_id' => $invoiceId, 'amount' => 1_500]],
    ])->assertStatus(422);
});

it('annule un règlement sans l effacer et redonne la facture pour due', function (): void {
    Queue::fake();
    $ids = seedCore();
    $invoiceId = seedValidatedInvoice($ids, 1_000_000);

    freshAuth();
    $paymentId = $this->withToken(tokenFor($ids['user_finance_manager']))->postJson('/api/v1/payments', [
        'company_id' => $ids['company'], 'party_id' => $ids['client'], 'method' => 'cheque',
        'method_reference' => '4471288', 'currency_code' => 'XOF',
        'amount' => 1_000_000, 'received_on' => now()->toDateString(),
    ])->json('id');

    freshAuth();
    $this->withToken(tokenFor($ids['user_finance_manager']))
        ->postJson("/api/v1/payments/{$paymentId}/cancel", ['reason' => 'Chèque revenu impayé'])
        ->assertOk();

    // Le fait d'encaissement survit à son annulation ; l'imputation, non.
    $payment = DB::table('payments')->where('id', $paymentId)->first();
    expect($payment)->not->toBeNull()
        ->and($payment->cancel_reason)->toBe('Chèque revenu impayé')
        ->and(DB::table('payment_allocations')->where('payment_id', $paymentId)->count())->toBe(0)
        ->and(DB::table('invoices')->where('id', $invoiceId)->value('payment_status'))->toBe('unpaid');
});

it('dresse la balance âgée par tranche de retard', function (): void {
    Queue::fake();
    $ids = seedCore();
    seedValidatedInvoice($ids, 500_000, now()->addDays(15)->toDateString());
    seedValidatedInvoice($ids, 250_000, now()->subDays(20)->toDateString());
    seedValidatedInvoice($ids, 1_200_000, now()->subDays(150)->toDateString());

    freshAuth();
    $aged = $this->withToken(tokenFor($ids['user_finance_manager']))->getJson('/api/v1/receivables');

    $aged->assertOk()
        ->assertJsonPath('totals.current', 500000)
        ->assertJsonPath('totals.1_30', 250000)
        ->assertJsonPath('totals.over_90', 1200000)
        ->assertJsonPath('totals.total', 1950000);
});

it('détaille ce qu un client doit encore, facture par facture', function (): void {
    Queue::fake();
    $ids = seedCore();
    seedValidatedInvoice($ids, 300_000);
    seedValidatedInvoice($ids, 700_000);

    freshAuth();
    $this->withToken(tokenFor($ids['user_finance_manager']))->postJson('/api/v1/payments', [
        'company_id' => $ids['company'], 'party_id' => $ids['client'], 'method' => 'cash',
        'currency_code' => 'XOF', 'amount' => 300_000, 'received_on' => now()->toDateString(),
    ])->assertCreated();

    freshAuth();
    $this->withToken(tokenFor($ids['user_finance_manager']))
        ->getJson("/api/v1/receivables/{$ids['client']}")
        ->assertOk()
        ->assertJsonCount(1, 'invoices')
        ->assertJsonPath('total', 700000);
});

it('applique le RBAC : le comptable encaisse mais n annule pas', function (): void {
    Queue::fake();
    $ids = seedCore();
    seedValidatedInvoice($ids, 500_000);

    freshAuth();
    $paymentId = $this->withToken(tokenFor($ids['user_accountant']))->postJson('/api/v1/payments', [
        'company_id' => $ids['company'], 'party_id' => $ids['client'], 'method' => 'cash',
        'currency_code' => 'XOF', 'amount' => 500_000, 'received_on' => now()->toDateString(),
    ])->assertCreated()->json('id');

    // Annuler un encaissement déjà porté en caisse relève du responsable.
    freshAuth();
    $this->withToken(tokenFor($ids['user_accountant']))
        ->postJson("/api/v1/payments/{$paymentId}/cancel", ['reason' => 'Erreur'])
        ->assertForbidden();
});

it('refuse de régler une facture en brouillon', function (): void {
    Queue::fake();
    $ids = seedCore();

    freshAuth();
    $draftId = $this->withToken(tokenFor($ids['user_admin']))->postJson('/api/v1/invoices', [
        'company_id' => $ids['company'], 'type' => 'invoice', 'party_id' => $ids['client'], 'currency_code' => 'XOF',
        'lines' => [['service_code' => 'x', 'description' => 'Fret', 'quantity' => 1, 'unit' => 'flat', 'unit_price' => 100000]],
    ])->json('id');

    freshAuth();
    $this->withToken(tokenFor($ids['user_finance_manager']))->postJson('/api/v1/payments', [
        'company_id' => $ids['company'], 'party_id' => $ids['client'], 'method' => 'cash',
        'currency_code' => 'XOF', 'amount' => 100_000, 'received_on' => now()->toDateString(),
        'allocations' => [['invoice_id' => $draftId, 'amount' => 100_000]],
    ])->assertStatus(422);
});
