<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Aéroports et compagnie nécessaires aux segments et à l'en-tête LTA.
 * Les FK flight_legs → airports et air_waybills → airlines l'imposent.
 */
function seedAirRefs(): string
{
    DB::table('countries')->insertOrIgnore([
        ['code2' => 'CI', 'code3' => 'CIV', 'name_fr' => "Côte d'Ivoire", 'name_en' => 'Ivory Coast', 'created_at' => now(), 'updated_at' => now()],
        ['code2' => 'FR', 'code3' => 'FRA', 'name_fr' => 'France', 'name_en' => 'France', 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('airports')->insertOrIgnore([
        ['iata' => 'ABJ', 'name' => 'Abidjan Félix-Houphouët-Boigny', 'country_code' => 'CI', 'created_at' => now(), 'updated_at' => now()],
        ['iata' => 'CDG', 'name' => 'Paris Charles-de-Gaulle', 'country_code' => 'FR', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $airlineId = (string) Str::uuid7();
    DB::table('airlines')->insert(['id' => $airlineId, 'awb_prefix' => '057', 'iata' => 'AF', 'name' => 'Air France Cargo', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

    return $airlineId;
}

/** LTA master valide (numéro mod 7) avec un segment de vol. */
function seedAwb(array $ids, string $airlineId): string
{
    $shipmentId = seedShipmentFor($ids, $ids['client'], 'IMP-AIR-0001');
    $awbId = (string) Str::uuid7();
    DB::table('air_waybills')->insert([
        'id' => $awbId, 'tenant_id' => $ids['tenant'], 'shipment_id' => $shipmentId,
        'type' => 'master', 'number' => '05712345675', 'airline_id' => $airlineId,
        'gross_weight_kg' => 320.5, 'volume_m3' => 4.2, 'packages_count' => 12,
        'status' => 'draft', 'shipper' => json_encode(['name' => 'Expéditeur SARL', 'city' => 'Paris', 'country' => 'France']),
        'consignee' => json_encode(['name' => 'Destinataire CI', 'city' => 'Abidjan', 'country' => "Côte d'Ivoire"]),
        'goods_description' => 'Pièces détachées automobiles',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('flight_legs')->insert([
        'id' => (string) Str::uuid7(), 'awb_id' => $awbId, 'position' => 1,
        'flight_number' => 'AF718', 'origin_iata' => 'CDG', 'destination_iata' => 'ABJ',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $awbId;
}

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
