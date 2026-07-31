<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// seedAirRefs() et seedAwb() vivent dans tests/Pest.php — partagés avec la
// suite de suivi aérien.

it('génère la LTA en PDF avec un nom de fichier lisible', function (): void {
    $ids = seedCore();
    $airlineId = seedAirRefs();
    $awbId = seedAwb($ids, $airlineId);

    $response = $this->withToken(tokenFor($ids['user_admin']))->get("/api/v1/air-waybills/{$awbId}/lta");

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertDownload('lta-05712345675.pdf');
    expect(str_starts_with((string) $response->getContent(), '%PDF'))->toBeTrue();
});

it('calcule le poids taxable IATA et le rend dans le détail', function (): void {
    $ids = seedCore();
    $airlineId = seedAirRefs();
    $awbId = seedAwb($ids, $airlineId);

    // Volume 4,2 m³ × 166,667 = 700,00 kg > 320,5 kg brut : le volume l'emporte.
    $awb = $this->withToken(tokenFor($ids['user_admin']))
        ->getJson("/api/v1/air-waybills/{$awbId}")->assertOk()->json();

    expect(round((float) $awb['chargeable_weight_kg']))->toBe(700.0)
        ->and($awb['airline']['name'])->toBe('Air France Cargo')
        ->and($awb['legs'])->toHaveCount(1);
});

it('refuse la LTA à un rôle sans awb.read', function (): void {
    $ids = seedCore();
    $airlineId = seedAirRefs();
    $awbId = seedAwb($ids, $airlineId);

    $this->withToken(tokenFor($ids['user_driver']))->get("/api/v1/air-waybills/{$awbId}/lta")
        ->assertForbidden();
});
