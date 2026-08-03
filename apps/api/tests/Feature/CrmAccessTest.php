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

it('laisse le comptable créer une fiche mais lui ferme la modification', function (): void {
    $ids = seedCore();
    $token = tokenFor($ids['user_accountant']);

    // La création reste ouverte…
    $party = $this->withToken($token)->postJson('/api/v1/parties', [
        'type' => 'client', 'kind' => 'company', 'name' => 'Nouvelle SARL',
        'currency_code' => 'XOF', 'payment_terms_days' => 45,
    ])->assertCreated()->json();

    // …mais corriger une fiche existante est réservé (admin, direction, finance).
    $this->withToken($token)->patchJson("/api/v1/parties/{$party['id']}", ['payment_terms_days' => 30])
        ->assertForbidden();
});

it('ferme la modification d\'un tiers au commercial, sans toucher la création', function (): void {
    $ids = seedCore();
    $token = tokenFor(seedUserWithRole($ids, 'sales', 'sales@test.local'));

    $party = $this->withToken($token)->postJson('/api/v1/parties', [
        'type' => 'prospect', 'kind' => 'company', 'name' => 'Prospect SA', 'currency_code' => 'XOF',
    ])->assertCreated()->json();

    $this->withToken($token)->patchJson("/api/v1/parties/{$party['id']}", ['payment_terms_days' => 15])
        ->assertForbidden();
});

it('réserve la modification d\'un tiers à la direction', function (): void {
    $ids = seedCore();

    $this->withToken(tokenFor($ids['user_director']))
        ->patchJson("/api/v1/parties/{$ids['client']}", ['payment_terms_days' => 60])
        ->assertOk()->assertJsonPath('payment_terms_days', 60);
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
