<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Réponse ShipsGo air simulée. La forme suit le motif v2 documenté ; elle
 * reste à confronter à une vraie réponse le jour où le compte a l'add-on
 * aérien actif (tout le mapping est isolé dans ShipsGoAirTranslator).
 *
 * @return array<string, mixed>
 */
function fakeAirShipment(): array
{
    return [
        'id' => 456,
        'awb_number' => '05712345675',
        'status' => 'En-Route',
        'airline' => ['name' => 'Air France Cargo', 'iata' => 'AF'],
        'route' => ['destination' => ['airport' => ['iata' => 'ABJ'], 'expected_date' => '2026-08-02T06:00:00Z']],
        'last_location' => ['iata' => 'ABJ'],
        'movements' => [
            ['event' => 'RCS', 'location' => ['iata' => 'CDG', 'name' => 'Paris CDG'], 'flight_number' => 'AF718', 'timestamp' => '2026-08-01T10:00:00Z'],
            ['event' => 'DEP', 'location' => ['iata' => 'CDG'], 'flight_number' => 'AF718', 'timestamp' => '2026-08-01T14:30:00Z'],
            ['event' => 'ARR', 'location' => ['iata' => 'ABJ'], 'flight_number' => 'AF718', 'timestamp' => '2026-08-01T20:15:00Z'],
        ],
    ];
}

/** Recherche vide → enregistrement → lecture. Enchaînement complet du connecteur. */
function fakeShipsGoAir(): void
{
    config(['services.shipsgo.api_key' => 'test-key', 'services.shipsgo.base_url' => 'https://api.shipsgo.com/v2']);

    Http::fake(function ($request) {
        $url = $request->url();
        if ($request->method() === 'POST') {
            return Http::response(['shipment' => ['id' => 456]]);
        }
        if (str_contains($url, '/air/shipments/456')) {
            return Http::response(['shipment' => fakeAirShipment()]);
        }

        return Http::response(['shipments' => []]); // recherche : pas encore suivie
    });
}

it('range le relevé de vol : statut, heures réelles et mouvements', function (): void {
    $ids = seedCore();
    $airlineId = seedAirRefs();
    $awbId = seedAwb($ids, $airlineId);
    fakeShipsGoAir();

    $summary = $this->withToken(tokenFor($ids['user_admin']))
        ->postJson("/api/v1/air-waybills/{$awbId}/track")->assertOk()->json();

    expect($summary['status'])->toBe('en_route')
        ->and($summary['new_events'])->toBe(3)
        ->and($summary['last_location'])->toBe('ABJ');

    $awb = DB::table('air_waybills')->where('id', $awbId)->first();
    expect($awb->tracking_status)->toBe('en_route')
        ->and($awb->last_location_iata)->toBe('ABJ')
        ->and($awb->last_tracked_at)->not->toBeNull()
        ->and($awb->shipsgo_ref)->toBe('456');

    $leg = DB::table('flight_legs')->where('awb_id', $awbId)->first();
    expect($leg->actual_departure_at)->not->toBeNull()
        ->and($leg->actual_arrival_at)->not->toBeNull();

    expect(DB::table('air_tracking_events')->where('awb_id', $awbId)->count())->toBe(3);
});

it('ne recrée pas les mouvements déjà connus au second passage', function (): void {
    $ids = seedCore();
    $airlineId = seedAirRefs();
    $awbId = seedAwb($ids, $airlineId);
    fakeShipsGoAir();
    $token = tokenFor($ids['user_admin']);

    $this->withToken($token)->postJson("/api/v1/air-waybills/{$awbId}/track")->assertOk();
    $second = $this->withToken($token)->postJson("/api/v1/air-waybills/{$awbId}/track")->assertOk()->json();

    expect($second['new_events'])->toBe(0);
    expect(DB::table('air_tracking_events')->where('awb_id', $awbId)->count())->toBe(3);
});

it('refuse le suivi à un rôle sans awb.update', function (): void {
    $ids = seedCore();
    $airlineId = seedAirRefs();
    $awbId = seedAwb($ids, $airlineId);

    $this->withToken(tokenFor($ids['user_driver']))->postJson("/api/v1/air-waybills/{$awbId}/track")
        ->assertForbidden();
});
