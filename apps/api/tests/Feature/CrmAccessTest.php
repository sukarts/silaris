<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('ouvre le CRM au comptable', function (): void {
    $ids = seedCore();

    // Le comptable facture : il lui faut les coordonnées, le RCCM et les
    // conditions de règlement du client.
    $this->withToken(tokenFor($ids['user_accountant']))
        ->getJson('/api/v1/parties?type=client')->assertOk();
});

it('ouvre le CRM au responsable financier', function (): void {
    $ids = seedCore();
    $finance = seedUserWithRole($ids, 'finance_manager', 'finance@test.local');

    $this->withToken(tokenFor($finance))->getJson('/api/v1/parties?type=client')->assertOk();
});

it('laisse le comptable créer et corriger une fiche client', function (): void {
    $ids = seedCore();
    $token = tokenFor($ids['user_accountant']);

    $party = $this->withToken($token)->postJson('/api/v1/parties', [
        'type' => 'client', 'kind' => 'company', 'name' => 'Nouvelle SARL',
        'currency_code' => 'XOF', 'payment_terms_days' => 45,
    ])->assertCreated()->json();

    $this->withToken($token)->patchJson("/api/v1/parties/{$party['id']}", [
        'tax_id' => 'CI-ABJ-2026-B-99887', 'payment_terms_days' => 30,
    ])->assertOk()->assertJsonPath('payment_terms_days', 30);
});

it('laisse le responsable financier créer et corriger une fiche client', function (): void {
    $ids = seedCore();
    $token = tokenFor(seedUserWithRole($ids, 'finance_manager', 'finance@test.local'));

    $party = $this->withToken($token)->postJson('/api/v1/parties', [
        'type' => 'client', 'kind' => 'individual', 'name' => 'Koffi Adjoua',
        'currency_code' => 'XOF', 'payment_terms_days' => 0,
    ])->assertCreated()->json();

    $this->withToken($token)->patchJson("/api/v1/parties/{$party['id']}", ['payment_terms_days' => 15])
        ->assertOk();
});

it('laisse la conversion d\'un prospect au commercial', function (): void {
    $ids = seedCore();

    // Transformer un prospect en client est un acte commercial, pas comptable.
    $this->withToken(tokenFor($ids['user_accountant']))
        ->postJson("/api/v1/parties/{$ids['client']}/convert")->assertForbidden();
});
