<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Silaris\Modules\Road\Application\Service\PositionIngestionService;

uses(RefreshDatabase::class);

/** Balise rattachée à un camion, avec mission en cours et arrêt géolocalisé. */
function seedTelematics(array $ids, float $stopLat = 5.3364, float $stopLon = -4.0728): array
{
    $truckId = (string) Str::uuid7();
    DB::table('trucks')->insert([
        'id' => $truckId, 'tenant_id' => $ids['tenant'], 'plate_number' => 'CI-9999-XX',
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $missionId = (string) Str::uuid7();
    DB::table('missions')->insert([
        'id' => $missionId, 'tenant_id' => $ids['tenant'], 'reference' => 'MIS-TEST-001',
        'truck_id' => $truckId, 'type' => 'delivery', 'status' => 'in_progress',
        'started_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('mission_stops')->insert([
        'id' => (string) Str::uuid7(), 'mission_id' => $missionId, 'position' => 1,
        'label' => 'Entrepôt client', 'address' => json_encode([]),
        'latitude' => $stopLat, 'longitude' => $stopLon,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $key = 'dev_'.bin2hex(random_bytes(24));
    $deviceId = (string) Str::uuid7();
    DB::table('tracking_devices')->insert([
        'id' => $deviceId, 'tenant_id' => $ids['tenant'], 'identifier' => 'IMEI-TEST-1',
        'label' => 'Balise test', 'kind' => 'beacon',
        'api_key_hash' => Hash::make($key), 'key_prefix' => substr($key, 0, 12),
        'truck_id' => $truckId, 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return ['key' => $key, 'device' => $deviceId, 'mission' => $missionId, 'truck' => $truckId];
}

it('ingère les positions d\'une balise et les rattache à la mission en cours', function (): void {
    $ids = seedCore();
    $ctx = seedTelematics($ids);

    $this->withHeader('X-Device-Key', $ctx['key'])
        ->postJson('/api/v1/telematics/positions', ['positions' => [
            ['latitude' => 5.2735, 'longitude' => -4.0083, 'recorded_at' => now()->subMinutes(30)->toIso8601String(), 'speed_kmh' => 40],
        ]])
        ->assertStatus(202)
        ->assertJson(['stored' => 1, 'duplicates' => 0, 'mission_id' => $ctx['mission']]);

    expect(DB::table('device_positions')->where('mission_id', $ctx['mission'])->count())->toBe(1);
});

it('ignore les points rejoués après une coupure réseau', function (): void {
    $ids = seedCore();
    $ctx = seedTelematics($ids);
    $payload = ['positions' => [
        ['latitude' => 5.28, 'longitude' => -4.01, 'recorded_at' => now()->subMinutes(20)->toIso8601String()],
    ]];

    $this->withHeader('X-Device-Key', $ctx['key'])->postJson('/api/v1/telematics/positions', $payload);
    $this->withHeader('X-Device-Key', $ctx['key'])->postJson('/api/v1/telematics/positions', $payload)
        ->assertStatus(202)
        ->assertJson(['stored' => 0, 'duplicates' => 1]);

    expect(DB::table('device_positions')->count())->toBe(1);
});

it('marque l\'arrêt atteint quand le véhicule entre dans le rayon de livraison', function (): void {
    $ids = seedCore();
    $ctx = seedTelematics($ids);

    // ~80 m de l'arrêt : dans le rayon.
    $this->withHeader('X-Device-Key', $ctx['key'])
        ->postJson('/api/v1/telematics/positions', ['positions' => [
            ['latitude' => 5.3360, 'longitude' => -4.0725, 'recorded_at' => now()->toIso8601String()],
        ]])
        ->assertStatus(202)
        ->assertJson(['arrivals' => 1]);

    expect(DB::table('mission_stops')->where('mission_id', $ctx['mission'])->value('arrived_at'))->not->toBeNull();
});

it('ne déclenche pas d\'arrivée pour un simple passage à proximité', function (): void {
    $ids = seedCore();
    $ctx = seedTelematics($ids);

    // ~2 km de l'arrêt : hors rayon.
    $this->withHeader('X-Device-Key', $ctx['key'])
        ->postJson('/api/v1/telematics/positions', ['positions' => [
            ['latitude' => 5.3550, 'longitude' => -4.0728, 'recorded_at' => now()->toIso8601String()],
        ]])
        ->assertStatus(202)
        ->assertJson(['arrivals' => 0]);

    expect(DB::table('mission_stops')->where('mission_id', $ctx['mission'])->value('arrived_at'))->toBeNull();
});

it('rejette une clé de balise inconnue', function (): void {
    seedCore();

    $this->withHeader('X-Device-Key', 'dev_'.bin2hex(random_bytes(24)))
        ->postJson('/api/v1/telematics/positions', ['positions' => [
            ['latitude' => 5.3, 'longitude' => -4.0, 'recorded_at' => now()->toIso8601String()],
        ]])
        ->assertUnauthorized();
});

it('refuse des coordonnées hors bornes', function (): void {
    $ids = seedCore();
    $ctx = seedTelematics($ids);

    $this->withHeader('X-Device-Key', $ctx['key'])
        ->postJson('/api/v1/telematics/positions', ['positions' => [
            ['latitude' => 120, 'longitude' => -4.0, 'recorded_at' => now()->toIso8601String()],
        ]])
        ->assertUnprocessable();
});

it('expose la trace de la mission avec la distance au prochain arrêt', function (): void {
    $ids = seedCore();
    $ctx = seedTelematics($ids);
    $this->withHeader('X-Device-Key', $ctx['key'])->postJson('/api/v1/telematics/positions', ['positions' => [
        ['latitude' => 5.2735, 'longitude' => -4.0083, 'recorded_at' => now()->subMinutes(10)->toIso8601String()],
    ]]);

    $response = $this->withToken(tokenFor($ids['user_admin']))
        ->getJson("/api/v1/missions/{$ctx['mission']}/positions")->assertOk();

    expect($response->json('positions'))->toHaveCount(1)
        ->and($response->json('distance_to_next_stop_m'))->toBeGreaterThan(1000)
        ->and($response->json('stops'))->toHaveCount(1);
});

it('calcule correctement une distance connue (Abidjan → Yopougon ≈ 10 km)', function (): void {
    $meters = PositionIngestionService::distanceMeters(5.2735, -4.0083, 5.3364, -4.0728);

    expect($meters)->toBeGreaterThan(9_000)->toBeLessThan(11_000);
});
