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

it('déduit la compagnie du booking du dossier', function (): void {
    $ids = seedCore();
    $shipmentId = seedShipmentFor($ids, $ids['client'], 'IMP-2026-00001');
    $carrierId = (string) Str::uuid7();
    DB::table('carriers')->insert([
        'id' => $carrierId, 'scac' => 'MSCU', 'name' => 'MSC', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('bookings')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $ids['tenant'], 'shipment_id' => $shipmentId,
        'carrier_id' => $carrierId, 'booking_number' => 'EBKG12345678', 'status' => 'requested',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Le conteneur est loué (préfixe SELU, aucun armateur) : seul le booking
    // dit sous quelle compagnie il voyage.
    app(TrackingSubscriber::class)->subscribe($ids['tenant'], $shipmentId, 'container', 'SELU4043526');

    expect(DB::table('tracking_subscriptions')->value('carrier_scac'))->toBe('MSCU');
});

it('déduit la compagnie du préfixe propriétaire à défaut de booking', function (): void {
    $ids = seedCore();
    $shipmentId = seedShipmentFor($ids, $ids['client'], 'IMP-2026-00001');
    DB::table('carriers')->insert([
        'id' => (string) Str::uuid7(), 'scac' => 'MSCU', 'name' => 'MSC', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    app(TrackingSubscriber::class)->subscribe($ids['tenant'], $shipmentId, 'container', 'MSCU1234566');

    expect(DB::table('tracking_subscriptions')->value('carrier_scac'))->toBe('MSCU');
});

it('laisse l\'abonnement en attente quand la compagnie reste inconnue', function (): void {
    $ids = seedCore();
    $shipmentId = seedShipmentFor($ids, $ids['client'], 'IMP-2026-00001');

    app(TrackingSubscriber::class)->subscribe($ids['tenant'], $shipmentId, 'container', 'SELU4043526');

    $refresh = $this->withToken(tokenFor($ids['user_admin']))
        ->postJson("/api/v1/shipments/{$shipmentId}/tracking/refresh")->assertOk()->json();

    // Le dossier ne doit pas paraître suivi alors qu'aucun appel n'est possible.
    expect($refresh['subscriptions'])->toBe(0)
        ->and($refresh['pending_carrier'])->toBe(1)
        ->and($refresh['errors'][0])->toContain('compagnie inconnue');
});

it('complète les abonnements en attente dès le booking enregistré', function (): void {
    $ids = seedCore();
    $shipmentId = seedShipmentFor($ids, $ids['client'], 'IMP-2026-00001');
    $containerId = seedContainer($ids, 'SELU4043526');
    $carrierId = (string) Str::uuid7();
    DB::table('carriers')->insert([
        'id' => $carrierId, 'scac' => 'MSCU', 'name' => 'MSC', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $token = tokenFor($ids['user_admin']);

    // Ordre réel : les conteneurs sont souvent affectés avant que la compagnie
    // soit arrêtée.
    $this->withToken($token)->postJson("/api/v1/containers/{$containerId}/assign", ['shipment_id' => $shipmentId]);
    expect(DB::table('tracking_subscriptions')->value('carrier_scac'))->toBeNull();

    $this->withToken($token)->postJson('/api/v1/bookings', [
        'shipment_id' => $shipmentId, 'carrier_id' => $carrierId, 'booking_number' => 'EBKG12345678',
    ])->assertCreated();

    expect(DB::table('tracking_subscriptions')->value('carrier_scac'))->toBe('MSCU');
});

it('met un dossier import sous suivi à partir du seul connaissement', function (): void {
    $ids = seedCore();
    $shipmentId = seedShipmentFor($ids, $ids['client'], 'IMP-2026-00001');
    DB::table('carriers')->insert([
        'id' => (string) Str::uuid7(), 'scac' => 'MSCU', 'name' => 'MSC', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // À l'import le transitaire ne fait pas le booking : le connaissement est
    // sa seule prise, et la compagnie lui apprend les conteneurs.
    $response = $this->withToken(tokenFor($ids['user_admin']))
        ->postJson("/api/v1/shipments/{$shipmentId}/tracking/subscribe", [
            'subject_type' => 'bl', 'number' => 'MEDUJ2260417', 'carrier_scac' => 'MSCU',
        ])->assertCreated()->json();

    expect($response['carrier_known'])->toBeTrue()
        ->and($response['new_events'])->toBeGreaterThan(0)
        ->and($response['containers'])->not->toBeEmpty();

    // Les conteneurs découverts sont affectés au dossier et suivis à leur tour.
    $containers = $this->withToken(tokenFor($ids['user_admin']))
        ->getJson("/api/v1/shipments/{$shipmentId}")->json('containers');

    expect($containers)->toHaveCount(1)
        ->and($containers[0]['number'])->toBe($response['containers'][0])
        ->and($containers[0]['tracking_status'])->toBe('active');
});

it('signale un conteneur déjà affecté ailleurs plutôt que d\'échouer', function (): void {
    $ids = seedCore();
    DB::table('carriers')->insert([
        'id' => (string) Str::uuid7(), 'scac' => 'MSCU', 'name' => 'MSC', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $token = tokenFor($ids['user_admin']);
    $first = seedShipmentFor($ids, $ids['client'], 'IMP-2026-00001');
    $second = seedShipmentFor($ids, $ids['client'], 'IMP-2026-00002');
    $payload = ['subject_type' => 'bl', 'number' => 'MEDUJ2260417', 'carrier_scac' => 'MSCU'];

    $this->withToken($token)->postJson("/api/v1/shipments/{$first}/tracking/subscribe", $payload)->assertCreated();
    // Un conteneur n'a qu'une affectation active : le second dossier le signale.
    $response = $this->withToken($token)
        ->postJson("/api/v1/shipments/{$second}/tracking/subscribe", $payload)->assertCreated()->json();

    expect($response['containers'])->toBeEmpty()
        ->and($response['containers_busy'])->not->toBeEmpty();
});

it('enregistre l\'abonnement même sans compagnie, sans interroger', function (): void {
    $ids = seedCore();
    $shipmentId = seedShipmentFor($ids, $ids['client'], 'IMP-2026-00001');

    $response = $this->withToken(tokenFor($ids['user_admin']))
        ->postJson("/api/v1/shipments/{$shipmentId}/tracking/subscribe", [
            'subject_type' => 'bl', 'number' => 'ABCD1234567',
        ])->assertCreated()->json();

    expect($response['carrier_known'])->toBeFalse()
        ->and($response['message'])->toContain('Précisez la compagnie');
});

it('conserve le relevé transporteur, pas seulement le statut', function (): void {
    $ids = seedCore();
    $shipmentId = seedShipmentFor($ids, $ids['client'], 'IMP-2026-00001');
    DB::table('carriers')->insert([
        'id' => (string) Str::uuid7(), 'scac' => 'MSCU', 'name' => 'MSC', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->withToken(tokenFor($ids['user_admin']))
        ->postJson("/api/v1/shipments/{$shipmentId}/tracking/subscribe", [
            'subject_type' => 'bl', 'number' => 'MEDUJ2260417', 'carrier_scac' => 'MSCU',
        ])->assertCreated();

    // L'agrégateur ne renvoie pas d'historique : tout ce qu'il sait du voyage
    // tient dans cette photo, qu'il serait dommage de réduire au statut.
    $container = $this->withToken(tokenFor($ids['user_admin']))
        ->getJson("/api/v1/shipments/{$shipmentId}")->json('containers.0');
    $snapshot = json_decode((string) $container['last_snapshot'], true);

    expect($snapshot)->toHaveKeys([
        'container_status', 'current_vessel_name', 'last_location', 'next_location', 'eta_final_destination',
    ]);
});

it('renseigne la compagnie d\'un abonnement déjà créé sans elle', function (): void {
    $ids = seedCore();
    $shipmentId = seedShipmentFor($ids, $ids['client'], 'IMP-2026-00001');
    $containerId = seedContainer($ids, 'SELU4043526');
    DB::table('carriers')->insert([
        'id' => (string) Str::uuid7(), 'scac' => 'MSCU', 'name' => 'MSC', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $token = tokenFor($ids['user_admin']);

    // Conteneur affecté avant toute compagnie connue : son abonnement reste muet.
    $this->withToken($token)->postJson("/api/v1/containers/{$containerId}/assign", ['shipment_id' => $shipmentId]);
    expect(DB::table('tracking_subscriptions')->where('subject_number', 'SELU4043526')->value('carrier_scac'))->toBeNull();

    // La compagnie saisie pour le connaissement débloque tout le dossier.
    $this->withToken($token)->postJson("/api/v1/shipments/{$shipmentId}/tracking/subscribe", [
        'subject_type' => 'bl', 'number' => 'MEDUJ2260417', 'carrier_scac' => 'MSCU',
    ])->assertCreated();

    expect(DB::table('tracking_subscriptions')->where('subject_number', 'SELU4043526')->value('carrier_scac'))->toBe('MSCU');
});
