<?php

declare(strict_types=1);

use Database\Seeders\CountrySeeder;
use Database\Seeders\PortSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Le générateur de code s'appuie sur le référentiel des ports pour retrouver
// le LOCODE officiel d'une ville ; les ports référencent les pays.
beforeEach(function (): void {
    (new CountrySeeder)->run();
    (new PortSeeder)->run();
});

it('reprend le LOCODE officiel du référentiel comme code agence', function (): void {
    $ids = seedCore();

    $code = $this->withToken(tokenFor($ids['user_admin']))
        ->postJson("/api/v1/admin/companies/{$ids['company']}/branches", [
            'name' => 'Agence Abidjan Port',
            'country_code' => 'ci',
            'city' => 'Abidjan',
        ])->assertCreated()->json('code');

    expect($code)->toBe('CIABJ');
});

it('rapproche les exonymes du référentiel (Marseille → Marseille-Fos)', function (): void {
    $ids = seedCore();

    $code = $this->withToken(tokenFor($ids['user_admin']))
        ->postJson("/api/v1/admin/companies/{$ids['company']}/branches", [
            'name' => 'Agence Marseille',
            'country_code' => 'FR',
            'city' => 'Marseille',
        ])->assertCreated()->json('code');

    expect($code)->toBe('FRMRS');
});

it('dérive un code lisible pour une ville absente du référentiel', function (): void {
    $ids = seedCore();

    $code = $this->withToken(tokenFor($ids['user_admin']))
        ->postJson("/api/v1/admin/companies/{$ids['company']}/branches", [
            'name' => 'Agence Yamoussoukro',
            'country_code' => 'CI',
            'city' => 'Yamoussoukro',
        ])->assertCreated()->json('code');

    expect($code)->toBe('CIYMS');
});

it('range les codes en collision plutôt que de refuser la création', function (): void {
    $ids = seedCore();
    $payload = ['name' => 'Agence Abidjan', 'country_code' => 'CI', 'city' => 'Abidjan'];

    $first = $this->withToken(tokenFor($ids['user_admin']))
        ->postJson("/api/v1/admin/companies/{$ids['company']}/branches", $payload)->json('code');
    $second = $this->withToken(tokenFor($ids['user_admin']))
        ->postJson("/api/v1/admin/companies/{$ids['company']}/branches", [...$payload, 'name' => 'Agence Abidjan Aéroport'])->json('code');

    expect($first)->toBe('CIABJ')->and($second)->toBe('CIABJ2');
});

it('enregistre un correspondant partenaire à l\'étranger', function (): void {
    $ids = seedCore();

    $branch = $this->withToken(tokenFor($ids['user_admin']))
        ->postJson("/api/v1/admin/companies/{$ids['company']}/branches", [
            'name' => 'Correspondant Belgique',
            'kind' => 'partner',
            'partner_name' => 'Antwerp Freight NV',
            'country_code' => 'BE',
            'city' => 'Anvers',
            'timezone' => 'Europe/Brussels',
        ])->assertCreated()->json();

    expect($branch['kind'])->toBe('partner')
        ->and($branch['partner_name'])->toBe('Antwerp Freight NV')
        ->and($branch['code'])->toBe('BEANR')
        ->and($branch['timezone'])->toBe('Europe/Brussels');
});

it('marque une agence comme propre par défaut', function (): void {
    $ids = seedCore();

    $kind = $this->withToken(tokenFor($ids['user_admin']))
        ->postJson("/api/v1/admin/companies/{$ids['company']}/branches", [
            'name' => 'Agence San-Pédro', 'country_code' => 'CI', 'city' => 'San-Pédro',
        ])->assertCreated()->json('kind');

    expect($kind)->toBe('own');
});

it('refuse un pays hors référentiel', function (): void {
    $ids = seedCore();

    $this->withToken(tokenFor($ids['user_admin']))
        ->postJson("/api/v1/admin/companies/{$ids['company']}/branches", [
            'name' => 'Agence fantôme', 'country_code' => 'ZZ', 'city' => 'Nulle part',
        ])->assertStatus(422);
});

it('donne un aperçu du code agence sans créer l\'agence', function (): void {
    $ids = seedCore();

    $code = $this->withToken(tokenFor($ids['user_admin']))
        ->getJson('/api/v1/admin/branches/code-preview?country_code=CI&city=Abidjan')
        ->assertOk()->json('code');

    expect($code)->toBe('CIABJ');
    expect(DB::table('branches')->where('code', 'CIABJ')->exists())->toBeFalse();
});
