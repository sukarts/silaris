<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Silaris\Modules\Tracking\Application\Service\TrackingSubscriber;

uses(RefreshDatabase::class);

/** Conteneur au format ISO 6346 valide, contrainte de la base. */
function seedContainer(array $ids, string $number = 'MSCU1234566'): string
{
    $id = (string) Str::uuid7();
    DB::table('containers')->insert([
        'id' => $id, 'tenant_id' => $ids['tenant'], 'number' => $number,
        'size_type' => '40HC', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

it('abonne le dossier au suivi dès qu\'un conteneur lui est affecté', function (): void {
    $ids = seedCore();
    $shipmentId = seedShipmentFor($ids, $ids['client'], 'IMP-2026-00001');
    $containerId = seedContainer($ids);

    $this->withToken(tokenFor($ids['user_admin']))
        ->postJson("/api/v1/containers/{$containerId}/assign", [
            'shipment_id' => $shipmentId, 'seal_number' => 'SL0099',
        ])->assertCreated();

    $subscription = DB::table('tracking_subscriptions')
        ->where('shipment_id', $shipmentId)->where('subject_type', 'container')->first();

    expect($subscription)->not->toBeNull()
        ->and($subscription->subject_number)->toBe('MSCU1234566')
        ->and($subscription->status)->toBe('active');
});

it('ne crée pas deux abonnements pour le même numéro', function (): void {
    $ids = seedCore();
    $shipmentId = seedShipmentFor($ids, $ids['client'], 'IMP-2026-00001');
    $subscriber = app(TrackingSubscriber::class);

    $first = $subscriber->subscribe($ids['tenant'], $shipmentId, 'container', 'MSCU1234566');
    $second = $subscriber->subscribe($ids['tenant'], $shipmentId, 'container', 'mscu 123-4566');

    // Les numéros se saisissent avec espaces et tirets : la normalisation évite
    // d'ouvrir un second abonnement sur la même boîte.
    expect($second)->toBe($first)
        ->and(DB::table('tracking_subscriptions')->where('shipment_id', $shipmentId)->count())->toBe(1);
});

it('ignore un numéro vide', function (): void {
    $ids = seedCore();
    $shipmentId = seedShipmentFor($ids, $ids['client'], 'IMP-2026-00001');

    expect(app(TrackingSubscriber::class)->subscribe($ids['tenant'], $shipmentId, 'container', '  '))->toBeNull()
        ->and(DB::table('tracking_subscriptions')->count())->toBe(0);
});

it('abonne au connaissement maître, jamais au house', function (): void {
    $ids = seedCore();
    $shipmentId = seedShipmentFor($ids, $ids['client'], 'IMP-2026-00001');
    $token = tokenFor($ids['user_admin']);
    $bl = [
        'shipment_id' => $shipmentId, 'number' => 'MSCUAB123456',
        'shipper' => ['name' => 'Expéditeur'], 'consignee' => ['name' => 'Destinataire'],
    ];

    $this->withToken($token)->postJson('/api/v1/bills-of-lading', [...$bl, 'type' => 'master'])->assertCreated();
    $this->withToken($token)->postJson('/api/v1/bills-of-lading', [...$bl, 'type' => 'house', 'number' => 'HBL-0001'])->assertCreated();

    $numbers = DB::table('tracking_subscriptions')->where('subject_type', 'bl')->pluck('subject_number');

    expect($numbers->all())->toBe(['MSCUAB123456']);
});

it('expose les conteneurs et l\'état du suivi sur la fiche dossier', function (): void {
    $ids = seedCore();
    $shipmentId = seedShipmentFor($ids, $ids['client'], 'IMP-2026-00001');
    $containerId = seedContainer($ids);
    $token = tokenFor($ids['user_admin']);
    $this->withToken($token)->postJson("/api/v1/containers/{$containerId}/assign", [
        'shipment_id' => $shipmentId, 'seal_number' => 'SL0099',
    ]);

    $containers = $this->withToken($token)->getJson("/api/v1/shipments/{$shipmentId}")
        ->assertOk()->json('containers');

    expect($containers)->toHaveCount(1)
        ->and($containers[0]['number'])->toBe('MSCU1234566')
        ->and($containers[0]['seal_number'])->toBe('SL0099')
        ->and($containers[0]['tracking_status'])->toBe('active');
});

it('réactive un abonnement en pause plutôt que d\'en ouvrir un second', function (): void {
    $ids = seedCore();
    $shipmentId = seedShipmentFor($ids, $ids['client'], 'IMP-2026-00001');
    $subscriber = app(TrackingSubscriber::class);
    $subscriber->subscribe($ids['tenant'], $shipmentId, 'container', 'MSCU1234566');
    DB::table('tracking_subscriptions')->update(['status' => 'paused', 'consecutive_failures' => 5]);

    // Un dossier rouvert doit reprendre son suivi, pas en ouvrir un doublon.
    $subscriber->subscribe($ids['tenant'], $shipmentId, 'container', 'MSCU1234566');

    $subscription = DB::table('tracking_subscriptions')->where('shipment_id', $shipmentId)->first();
    expect(DB::table('tracking_subscriptions')->count())->toBe(1)
        ->and($subscription->status)->toBe('active')
        ->and($subscription->consecutive_failures)->toBe(0);
});
