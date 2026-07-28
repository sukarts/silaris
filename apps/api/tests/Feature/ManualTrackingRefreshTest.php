<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function seedTrackedShipment(array $ids): array
{
    $shipmentId = (string) Str::uuid7();
    DB::table('shipments')->insert([
        'id' => $shipmentId, 'tenant_id' => $ids['tenant'], 'reference' => 'TST-2026-00001',
        'client_id' => $ids['client'], 'branch_id' => $ids['branch'], 'company_id' => $ids['company'],
        'agent_id' => $ids['user_transit_agent'], 'direction' => 'import', 'mode' => 'sea_fcl',
        'status' => 'transit', 'workflow_definition_id' => $ids['workflow'], 'incoterm_code' => 'CIF',
        'origin_locode' => 'CNSHA', 'destination_locode' => 'CIABJ',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $subscriptionId = (string) Str::uuid7();
    DB::table('tracking_subscriptions')->insert([
        'id' => $subscriptionId, 'tenant_id' => $ids['tenant'], 'shipment_id' => $shipmentId,
        'subject_type' => 'container', 'subject_number' => 'MEDU9091004',
        'carrier_scac' => 'MSCU', 'status' => 'active', 'consecutive_failures' => 0,
        // Vient d'être interrogé : la cadence planifiée l'aurait ignoré.
        'last_polled_at' => now()->subMinutes(5),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$shipmentId, $subscriptionId];
}

it('interroge immédiatement les compagnies, hors cadence planifiée', function (): void {
    $ids = seedCore();
    [$shipmentId] = seedTrackedShipment($ids);
    Config::set('services.shipsgo.api_key', 'test-key');
    Config::set('services.shipsgo.base_url', 'https://api.shipsgo.com/v2');

    Http::fake([
        'api.shipsgo.com/v2/ocean/shipments?*' => Http::response(['shipments' => [['id' => 42]]]),
        'api.shipsgo.com/v2/ocean/shipments/42' => Http::response(['shipment' => [
            'status' => 'DISCHARGED',
            'route' => ['port_of_discharge' => ['location' => ['name' => 'Abidjan', 'code' => 'CIABJ'], 'expected_date' => '2026-07-28T06:00:00Z']],
            'containers' => [[
                'number' => 'MSKU8842016', 'size' => 40, 'type' => 'HC',
                'movements' => [
                    ['event' => 'DISC', 'timestamp' => '2026-07-20T14:30:00Z',
                        'location' => ['name' => 'Abidjan', 'code' => 'CIABJ'], 'vessel' => ['name' => 'MSC LORETO']],
                ],
            ]],
        ]]),
    ]);

    $response = $this->withToken(tokenFor($ids['user_admin']))
        ->postJson("/api/v1/shipments/{$shipmentId}/tracking/refresh");

    $response->assertOk()->assertJson(['subscriptions' => 1, 'polled' => 1]);
    expect($response->json('new_events'))->toBeGreaterThan(0);
    expect(DB::table('tracking_events')->where('shipment_id', $shipmentId)->count())->toBeGreaterThan(0);
});

it('refuse l\'actualisation manuelle sans shipments.update', function (): void {
    $ids = seedCore();
    [$shipmentId] = seedTrackedShipment($ids);

    $this->withToken(tokenFor($ids['user_driver']))
        ->postJson("/api/v1/shipments/{$shipmentId}/tracking/refresh")
        ->assertForbidden();
});
