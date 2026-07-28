<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Silaris\Modules\CarrierConnect\Infrastructure\Support\ShipsGoTranslator;
use Silaris\Modules\CarrierConnect\Infrastructure\Support\StatusNormalizer;

uses(RefreshDatabase::class);

const SHIPSGO_SECRET = 'un-secret-de-compte-suffisamment-long';

/** Expédition telle que ShipsGo la renvoie — calquée sur une réponse réelle. */
function shipsGoShipment(string $container = 'MSNU9682848'): array
{
    return [
        'id' => 6514833,
        'status' => 'SAILING',
        'container_number' => $container,
        'carrier' => ['scac' => 'MSCU', 'name' => 'MSC'],
        'route' => [
            'port_of_loading' => ['location' => ['name' => 'NANSHA', 'code' => 'CNNSA'], 'actual_date' => '2026-06-06T12:00:00Z'],
            'port_of_discharge' => ['location' => ['name' => 'ABIDJAN', 'code' => 'CIABJ'], 'expected_date' => '2026-08-05T12:00:00Z'],
        ],
        'containers' => [[
            'number' => $container,
            'status' => 'SAILING',
            'size' => 40,
            'type' => 'HC',
            'movements' => [
                ['event' => 'EMSH', 'timestamp' => '2026-06-02T12:00:00Z', 'location' => ['name' => 'NANSHA', 'code' => 'CNNSA']],
                ['event' => 'GTIN', 'timestamp' => '2026-06-02T12:30:00Z', 'location' => ['name' => 'NANSHA', 'code' => 'CNNSA']],
                ['event' => 'LOAD', 'timestamp' => '2026-06-06T11:00:00Z', 'location' => ['name' => 'NANSHA', 'code' => 'CNNSA'],
                    'vessel' => ['name' => 'MSC NICOLA MASTRO', 'imo' => '9857305'], 'voyage' => 'FY621A'],
                ['event' => 'DEPA', 'timestamp' => '2026-06-06T12:00:00Z', 'location' => ['name' => 'NANSHA', 'code' => 'CNNSA'],
                    'vessel' => ['name' => 'MSC NICOLA MASTRO'], 'voyage' => 'FY621A'],
                // Transbordement : la séquence se répète sur une escale intermédiaire.
                ['event' => 'DISC', 'timestamp' => '2026-07-10T08:00:00Z', 'location' => ['name' => 'TANGER MED', 'code' => 'MAPTM']],
                ['event' => 'LOAD', 'timestamp' => '2026-07-12T08:00:00Z', 'location' => ['name' => 'TANGER MED', 'code' => 'MAPTM'],
                    'vessel' => ['name' => 'MSC LORETO'], 'voyage' => 'FJ427A'],
                ['event' => 'ARRV', 'timestamp' => '2026-08-05T12:00:00Z', 'location' => ['name' => 'ABIDJAN', 'code' => 'CIABJ'],
                    'vessel' => ['name' => 'MSC LORETO'], 'voyage' => 'FJ427A'],
            ],
        ]],
    ];
}

function seedShipsGoSubscription(array $ids, string $number = 'MSNU9682848'): array
{
    $shipmentId = seedShipmentFor($ids, $ids['client'], 'IMP-2026-00001');
    $subscriptionId = (string) Str::uuid7();
    DB::table('tracking_subscriptions')->insert([
        'id' => $subscriptionId, 'tenant_id' => $ids['tenant'], 'shipment_id' => $shipmentId,
        'subject_type' => 'container', 'subject_number' => $number, 'carrier_scac' => 'MSCU',
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$shipmentId, $subscriptionId];
}

it('calcule la signature comme ShipsGo la documente', function (): void {
    // Vecteur de test publié par ShipsGo : si notre calcul le reproduit, la
    // validation des notifications réelles est correcte.
    expect(hash_hmac('sha256', '{"message":"You shall not pass!"}', 'SUPER_LONG_AND_SECURE_SECRET_KEY'))
        ->toBe('9527e0c9463e6f5b01a0af50aecb4658ff50c6b25d3efa8e5c8dea7e4b763772');
});

it('traduit tout l\'historique des mouvements, transbordement compris', function (): void {
    $result = (new ShipsGoTranslator(new StatusNormalizer))->translate(shipsGoShipment());

    expect($result->events)->toHaveCount(7)
        ->and($result->containerNumbers)->toBe(['MSNU9682848'])
        ->and($result->eta?->format('Y-m-d'))->toBe('2026-08-05')
        ->and($result->atd?->format('Y-m-d'))->toBe('2026-06-06');

    // Le LOCODE est conservé : c'est ce que l'ancien agrégateur ne donnait pas.
    expect($result->events[0]->locationLocode)->toBe('CNNSA')
        ->and($result->events[4]->locationLocode)->toBe('MAPTM');

    // Le voyage change à l'escale : deux navires, donc un transbordement.
    $vessels = array_values(array_unique(array_filter(
        array_map(static fn ($event) => $event->rawPayload['vessel'] ?? null, $result->events),
    )));
    expect($vessels)->toBe(['MSC NICOLA MASTRO', 'MSC LORETO']);

    expect($result->snapshot['current_vessel_name'])->toBe('MSC LORETO')
        ->and($result->snapshot['container_type'])->toBe('40 HC');
});

it('accepte une notification correctement signée et enregistre les événements', function (): void {
    $ids = seedCore();
    [$shipmentId] = seedShipsGoSubscription($ids);
    Config::set('services.shipsgo.webhook_secret', SHIPSGO_SECRET);

    $payload = json_encode(['event' => ['name' => 'OCEAN.SHIPMENTS.SHIPMENT_UPDATED'], 'shipment' => shipsGoShipment()]);

    $this->call('POST', '/api/v1/webhooks/shipsgo', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SHIPSGO_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $payload, SHIPSGO_SECRET),
    ], $payload)->assertOk()->assertJsonPath('status', 'ok');

    expect(DB::table('tracking_events')->where('shipment_id', $shipmentId)->count())->toBe(7);
    // La photo du voyage est conservée pour l'affichage du dossier.
    expect(DB::table('tracking_subscriptions')->value('last_snapshot'))->toContain('MSC LORETO');
});

it('rejette une notification mal signée', function (): void {
    $ids = seedCore();
    [$shipmentId] = seedShipsGoSubscription($ids);
    Config::set('services.shipsgo.webhook_secret', SHIPSGO_SECRET);

    $payload = json_encode(['shipment' => shipsGoShipment()]);

    $this->call('POST', '/api/v1/webhooks/shipsgo', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SHIPSGO_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $payload, 'mauvais-secret'),
    ], $payload)->assertStatus(401);

    expect(DB::table('tracking_events')->where('shipment_id', $shipmentId)->count())->toBe(0);
});

it('rejette une notification sans signature', function (): void {
    seedCore();
    Config::set('services.shipsgo.webhook_secret', SHIPSGO_SECRET);

    $this->call('POST', '/api/v1/webhooks/shipsgo', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')
        ->assertStatus(401);
});

it('ignore une notification portant un numéro qu\'aucun dossier ne suit', function (): void {
    seedCore();
    Config::set('services.shipsgo.webhook_secret', SHIPSGO_SECRET);

    $payload = json_encode(['shipment' => shipsGoShipment('TCLU1234567')]);

    $this->call('POST', '/api/v1/webhooks/shipsgo', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SHIPSGO_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $payload, SHIPSGO_SECRET),
    ], $payload)->assertStatus(202)->assertJsonPath('status', 'unknown');

    expect(DB::table('tracking_events')->count())->toBe(0);
});
