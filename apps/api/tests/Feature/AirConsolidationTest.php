<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** HAWB (format libre, pas de contrôle mod 7) rattaché au même dossier. */
function seedHouse(array $ids, string $shipmentId, string $number, float $gross = 100, int $packages = 5): string
{
    $id = (string) Str::uuid7();
    DB::table('air_waybills')->insert([
        'id' => $id, 'tenant_id' => $ids['tenant'], 'shipment_id' => $shipmentId,
        'type' => 'house', 'number' => $number,
        'gross_weight_kg' => $gross, 'packages_count' => $packages, 'status' => 'draft',
        'shipper' => '{}', 'consignee' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

it('rattache un HAWB à un MAWB puis l\'en détache', function (): void {
    $ids = seedCore();
    $airlineId = seedAirRefs();
    $masterId = seedAwb($ids, $airlineId);
    $shipmentId = DB::table('air_waybills')->where('id', $masterId)->value('shipment_id');
    $houseId = seedHouse($ids, $shipmentId, 'HAWB-001');
    $token = tokenFor($ids['user_admin']);

    // Rattachement.
    $this->withToken($token)->patchJson("/api/v1/air-waybills/{$houseId}/consolidation", ['parent_id' => $masterId])
        ->assertOk();
    expect(DB::table('air_waybills')->where('id', $houseId)->value('parent_id'))->toBe($masterId);

    // Le master compte désormais un HAWB, avec son poids consolidé.
    $master = $this->withToken($token)->getJson("/api/v1/air-waybills/{$masterId}")->assertOk()->json();
    expect($master['consolidation']['houses_count'])->toBe(1)
        ->and((float) $master['consolidation']['gross_weight_kg'])->toBe(100.0)
        ->and($master['consolidation']['packages_count'])->toBe(5);

    // Détachement.
    $this->withToken($token)->patchJson("/api/v1/air-waybills/{$houseId}/consolidation", ['parent_id' => null])
        ->assertOk();
    expect(DB::table('air_waybills')->where('id', $houseId)->value('parent_id'))->toBeNull();
});

it('compte les HAWB rattachés dans la liste', function (): void {
    $ids = seedCore();
    $airlineId = seedAirRefs();
    $masterId = seedAwb($ids, $airlineId);
    $shipmentId = DB::table('air_waybills')->where('id', $masterId)->value('shipment_id');
    $houseId = seedHouse($ids, $shipmentId, 'HAWB-001');
    $token = tokenFor($ids['user_admin']);

    $this->withToken($token)->patchJson("/api/v1/air-waybills/{$houseId}/consolidation", ['parent_id' => $masterId])->assertOk();

    $masters = $this->withToken($token)->getJson('/api/v1/air-waybills?type=master')->assertOk()->json('data');
    expect(collect($masters)->firstWhere('id', $masterId)['houses_count'])->toBe(1);
});

it('refuse de rattacher à autre chose qu\'un MAWB', function (): void {
    $ids = seedCore();
    $airlineId = seedAirRefs();
    $masterId = seedAwb($ids, $airlineId);
    $shipmentId = DB::table('air_waybills')->where('id', $masterId)->value('shipment_id');
    $houseA = seedHouse($ids, $shipmentId, 'HAWB-A');
    $houseB = seedHouse($ids, $shipmentId, 'HAWB-B');

    // Un HAWB n'est pas un parent valide.
    $this->withToken(tokenFor($ids['user_admin']))
        ->patchJson("/api/v1/air-waybills/{$houseA}/consolidation", ['parent_id' => $houseB])
        ->assertStatus(422);

    // On ne consolide pas un master (il est le sommet).
    $this->withToken(tokenFor($ids['user_admin']))
        ->patchJson("/api/v1/air-waybills/{$masterId}/consolidation", ['parent_id' => null])
        ->assertNotFound();
});

it('refuse la consolidation à un rôle sans awb.update', function (): void {
    $ids = seedCore();
    $airlineId = seedAirRefs();
    $masterId = seedAwb($ids, $airlineId);
    $shipmentId = DB::table('air_waybills')->where('id', $masterId)->value('shipment_id');
    $houseId = seedHouse($ids, $shipmentId, 'HAWB-001');

    $this->withToken(tokenFor($ids['user_driver']))
        ->patchJson("/api/v1/air-waybills/{$houseId}/consolidation", ['parent_id' => $masterId])
        ->assertForbidden();
});
