<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('répond avec les groupes autorisés par les permissions du demandeur', function (): void {
    $ids = seedCore();

    // Admin : tous les groupes présents.
    $this->withToken(tokenFor($ids['user_admin']))->getJson('/api/v1/search?q=test')
        ->assertOk()
        ->assertJsonStructure(['query', 'groups' => ['shipments', 'parties', 'containers', 'bookings', 'invoices']]);
});

it('cache les groupes hors périmètre : un chauffeur ne voit ni CRM ni factures', function (): void {
    $ids = seedCore();

    $groups = $this->withToken(tokenFor($ids['user_driver']))->getJson('/api/v1/search?q=test')
        ->assertOk()->json('groups');

    expect($groups)->not->toHaveKeys(['parties', 'invoices']);
});

it('exige au moins 2 caractères', function (): void {
    $ids = seedCore();

    $this->withToken(tokenFor($ids['user_admin']))->getJson('/api/v1/search?q=a')
        ->assertUnprocessable();
});
