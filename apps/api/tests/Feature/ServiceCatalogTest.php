<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('propose le barème des débours à la saisie', function (): void {
    $ids = seedCore();

    $rows = collect($this->withToken(tokenFor($ids['user_admin']))
        ->getJson('/api/v1/service-catalog')->assertOk()->json('data'));

    // Poste au tarif fixe distinct selon le conteneur.
    $acconage = $rows->firstWhere('code', 'ACCONAGE');
    expect((float) $acconage['default_tc20'])->toBe(202000.0)
        ->and((float) $acconage['default_tc40'])->toBe(405000.0);

    // Poste calculé sur une base : pas de montant, une note de règle.
    $assurance = $rows->firstWhere('code', 'ASSURANCE');
    expect($assurance['default_tc40'])->toBeNull()
        ->and($assurance['pricing_note'])->toContain('0,15');
});
