<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('valide une facture (numéro légal, échéance) puis la protège, et génère un avoir lié', function (): void {
    Queue::fake();
    $ids = seedCore();
    $adminToken = tokenFor($ids['user_admin']);

    $draft = $this->withToken($adminToken)->postJson('/api/v1/invoices', [
        'company_id' => $ids['company'], 'type' => 'invoice', 'party_id' => $ids['client'],
        'currency_code' => 'XOF',
        'lines' => [['service_code' => 'freight', 'description' => 'Fret', 'quantity' => 2, 'unit' => 'container', 'unit_price' => 500000]],
    ]);
    $draft->assertCreated();
    $invoiceId = $draft->json('id');
    expect($draft->json('number'))->toBeNull()->and((float) $draft->json('total_excl_tax'))->toBe(1000000.0);

    // Validation → numéro + échéance (+30 j conditions client)
    $validated = $this->withToken($adminToken)->postJson("/api/v1/invoices/{$invoiceId}/validate");
    $validated->assertOk();
    expect($validated->json('number'))->toBe('F-'.date('Y').'-0001')
        ->and($validated->json('status'))->toBe('validated')
        ->and($validated->json('due_date'))->toContain(now()->addDays(30)->toDateString());

    // Facture validée intouchable via PATCH (filtre draft → 404)
    freshAuth();
    $this->withToken($adminToken)->patchJson("/api/v1/invoices/{$invoiceId}", [
        'company_id' => $ids['company'], 'type' => 'invoice', 'party_id' => $ids['client'], 'currency_code' => 'XOF',
        'lines' => [['service_code' => 'x', 'description' => 'x', 'quantity' => 1, 'unit' => 'flat', 'unit_price' => 1]],
    ])->assertNotFound();

    // Avoir lié avec motif obligatoire
    $creditNote = $this->withToken($adminToken)->postJson("/api/v1/invoices/{$invoiceId}/credit-note", ['reason' => 'Erreur']);
    $creditNote->assertCreated();
    expect($creditNote->json('type'))->toBe('credit_note')
        ->and($creditNote->json('original_invoice_id'))->toBe($invoiceId);
});
