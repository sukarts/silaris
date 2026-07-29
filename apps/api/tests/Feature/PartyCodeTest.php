<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('génère le code du tiers et ignore celui qu on lui envoie', function (): void {
    // Le code est une référence système : le laisser saisir avait produit des
    // fiches en « DAI » ou « D&F », hors nomenclature.
    $ids = seedCore();

    freshAuth();
    $response = $this->withToken(tokenFor($ids['user_admin']))->postJson('/api/v1/parties', [
        'type' => 'client', 'kind' => 'company', 'name' => 'DIAOUNE AGRO INDUSTRIE SARL',
        'code' => 'DAI',
    ]);

    $response->assertCreated();
    expect($response->json('code'))->toBe('CLI-0001')->not->toBe('DAI');
});

it('refuse de changer le code d un tiers existant', function (): void {
    $ids = seedCore();

    freshAuth();
    $partyId = $this->withToken(tokenFor($ids['user_admin']))->postJson('/api/v1/parties', [
        'type' => 'supplier', 'kind' => 'company', 'supplier_kind' => 'trucker', 'name' => 'SGSL-CI',
    ])->json('id');

    freshAuth();
    $this->withToken(tokenFor($ids['user_admin']))
        ->patchJson("/api/v1/parties/{$partyId}", ['code' => 'SGSL', 'name' => 'SGSL-CI'])
        ->assertOk();

    expect(DB::table('parties')->where('id', $partyId)->value('code'))->toBe('FOU-0001');
});

it('remet dans la nomenclature les codes saisis à la main', function (): void {
    $ids = seedCore();

    $conforme = ['CLI-0099' => 'MOOBI'];

    foreach (['DAI' => 'DIAOUNE AGRO INDUSTRIE SARL', 'D&F' => 'DIAOUNE & FRERE'] + $conforme as $code => $name) {
        DB::table('parties')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $ids['tenant'], 'type' => 'client',
            'code' => $code, 'name' => $name, 'notification_prefs' => '{}', 'tags' => '[]',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // Sans --apply, rien n'est écrit : renommer change ce qui s'affiche sur les
    // factures passées, cela se regarde avant de se décider.
    $this->artisan('silaris:normalize-party-codes')->assertSuccessful();
    expect(DB::table('parties')->where('code', 'DAI')->exists())->toBeTrue();

    $this->artisan('silaris:normalize-party-codes', ['--apply' => true])->assertSuccessful();

    expect(DB::table('parties')->where('code', 'DAI')->exists())->toBeFalse()
        ->and(DB::table('parties')->where('code', 'D&F')->exists())->toBeFalse()
        ->and(DB::table('parties')->where('name', 'DIAOUNE AGRO INDUSTRIE SARL')->value('code'))->toMatch('/^CLI-\d{4}$/')
        // Un code déjà conforme n'est pas touché : la commande ne renumérote
        // pas le portefeuille, elle rattrape les saisies hors nomenclature.
        ->and(DB::table('parties')->where('name', 'MOOBI')->value('code'))->toBe('CLI-0099');
});
