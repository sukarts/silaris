<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** Transporteur affrété enregistré au CRM comme fournisseur. */
function seedCarrier(array $ids, string $name = 'Trans-Ivoire SARL'): string
{
    $carrierId = (string) Str::uuid7();
    DB::table('parties')->insert([
        'id' => $carrierId, 'tenant_id' => $ids['tenant'], 'type' => 'supplier', 'supplier_kind' => 'trucker',
        'code' => 'FOU-'.Str::random(6), 'name' => $name, 'payment_terms_days' => 30,
        'notification_prefs' => '{}', 'tags' => '[]', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $carrierId;
}

it('enregistre un fournisseur transporteur depuis le CRM', function (): void {
    $ids = seedCore();

    $party = $this->withToken(tokenFor($ids['user_admin']))
        ->postJson('/api/v1/parties', [
            'type' => 'supplier', 'kind' => 'company', 'supplier_kind' => 'trucker',
            'name' => 'Trans-Ivoire SARL', 'currency_code' => 'XOF',
        ])->assertCreated()->json();

    expect($party['type'])->toBe('supplier')
        ->and($party['supplier_kind'])->toBe('trucker')
        ->and($party['code'])->toStartWith('FOU');
});

it('rattache un camion et un chauffeur au prestataire qui les fournit', function (): void {
    $ids = seedCore();
    $carrierId = seedCarrier($ids);
    $token = tokenFor($ids['user_admin']);

    $truck = $this->withToken($token)->postJson('/api/v1/fleet/trucks', [
        'plate_number' => '1234AB01', 'type' => 'tracteur', 'carrier_party_id' => $carrierId,
    ])->assertCreated()->json();
    $driver = $this->withToken($token)->postJson('/api/v1/fleet/drivers', [
        'name' => 'Kouassi Yao', 'carrier_party_id' => $carrierId,
    ])->assertCreated()->json();

    expect($truck['carrier_party_id'])->toBe($carrierId)
        ->and($driver['carrier_party_id'])->toBe($carrierId);
});

it('confie une mission à un transporteur avec son numéro d\'ordre', function (): void {
    $ids = seedCore();
    $carrierId = seedCarrier($ids);
    $token = tokenFor($ids['user_admin']);
    $truckId = $this->withToken($token)->postJson('/api/v1/fleet/trucks', [
        'plate_number' => '1234AB01', 'carrier_party_id' => $carrierId,
    ])->json('id');

    $mission = $this->withToken($token)->postJson('/api/v1/missions', [
        'type' => 'delivery', 'carrier_party_id' => $carrierId, 'carrier_reference' => 'OT-2026-014',
        'truck_id' => $truckId, 'stops' => [['label' => "Port d'Abidjan"], ['label' => 'Entrepôt client']],
    ])->assertCreated()->json();

    expect($mission['carrier_party_id'])->toBe($carrierId)
        ->and($mission['carrier_reference'])->toBe('OT-2026-014');
});

it('refuse un moyen affrété sur une mission sans transporteur', function (): void {
    $ids = seedCore();
    $carrierId = seedCarrier($ids);
    $token = tokenFor($ids['user_admin']);
    $truckId = $this->withToken($token)->postJson('/api/v1/fleet/trucks', [
        'plate_number' => '1234AB01', 'carrier_party_id' => $carrierId,
    ])->json('id');

    $this->withToken($token)->postJson('/api/v1/missions', ['type' => 'delivery', 'truck_id' => $truckId])
        ->assertStatus(422)->assertJsonPath('errors.truck_id.0', 'Ce moyen appartient à un prestataire : renseignez le transporteur de la mission.');
});

it('refuse le camion d\'un transporteur sous la bannière d\'un autre', function (): void {
    $ids = seedCore();
    $token = tokenFor($ids['user_admin']);
    $first = seedCarrier($ids, 'Trans-Ivoire SARL');
    $second = seedCarrier($ids, 'Sahel Logistique');
    $truckId = $this->withToken($token)->postJson('/api/v1/fleet/trucks', [
        'plate_number' => '1234AB01', 'carrier_party_id' => $first,
    ])->json('id');

    $this->withToken($token)->postJson('/api/v1/missions', [
        'type' => 'delivery', 'carrier_party_id' => $second, 'truck_id' => $truckId,
    ])->assertStatus(422)->assertJsonPath('errors.truck_id.0', 'Ce moyen appartient à un autre transporteur que celui de la mission.');
});

it('refuse un moyen de la flotte propre sur une mission affrétée', function (): void {
    $ids = seedCore();
    $carrierId = seedCarrier($ids);
    $token = tokenFor($ids['user_admin']);
    $truckId = $this->withToken($token)->postJson('/api/v1/fleet/trucks', ['plate_number' => '9999XX01'])->json('id');

    $this->withToken($token)->postJson('/api/v1/missions', [
        'type' => 'delivery', 'carrier_party_id' => $carrierId, 'truck_id' => $truckId,
    ])->assertStatus(422)->assertJsonPath('errors.truck_id.0', 'Ce moyen appartient à la flotte propre : retirez le transporteur affrété.');
});

it('ne montre jamais le sous-traitant au client', function (): void {
    $ids = seedCore();
    $carrierId = seedCarrier($ids);
    $token = tokenFor($ids['user_admin']);
    $shipmentId = seedShipmentFor($ids, $ids['client'], 'IMP-2026-00001');
    $truckId = $this->withToken($token)->postJson('/api/v1/fleet/trucks', [
        'plate_number' => '1234AB01', 'carrier_party_id' => $carrierId,
    ])->json('id');
    $driverId = $this->withToken($token)->postJson('/api/v1/fleet/drivers', [
        'name' => 'Kouassi Yao', 'carrier_party_id' => $carrierId,
    ])->json('id');
    $this->withToken($token)->postJson('/api/v1/missions', [
        'shipment_id' => $shipmentId, 'type' => 'delivery', 'carrier_party_id' => $carrierId,
        'carrier_reference' => 'OT-2026-014', 'truck_id' => $truckId, 'driver_id' => $driverId,
    ])->assertCreated();

    // Portail client : le dossier ne laisse filtrer ni le transporteur, ni le
    // chauffeur, ni le véhicule — le client n'a pas à savoir qui sous-traite.
    $portalToken = portalTokenFor($ids['portal']);
    freshAuth();
    $portal = $this->withToken($portalToken)
        ->getJson("/api/v1/portal/shipments/{$shipmentId}")->assertOk()->content();

    expect($portal)->not->toContain('Trans-Ivoire SARL')
        ->and($portal)->not->toContain('Kouassi Yao')
        ->and($portal)->not->toContain('1234AB01')
        ->and($portal)->not->toContain('OT-2026-014')
        ->and($portal)->not->toContain('carrier');
});

it('conserve la signature et le lieu recueillis par l\'exploitant', function (): void {
    $ids = seedCore();
    $carrierId = seedCarrier($ids);
    $token = tokenFor($ids['user_admin']);
    $truckId = $this->withToken($token)->postJson('/api/v1/fleet/trucks', [
        'plate_number' => '1234AB01', 'carrier_party_id' => $carrierId,
    ])->json('id');
    $missionId = $this->withToken($token)->postJson('/api/v1/missions', [
        'type' => 'delivery', 'carrier_party_id' => $carrierId, 'truck_id' => $truckId,
    ])->json('id');
    $this->withToken($token)->postJson("/api/v1/missions/{$missionId}/transition", ['status' => 'in_progress']);

    // Le POD est saisi sur place par l'exploitant : le destinataire signe sur
    // son écran, et la position atteste du lieu de la remise.
    $signature = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUg==';
    $pod = $this->withToken($token)->postJson("/api/v1/missions/{$missionId}/pod", [
        'recipient_name' => 'Konan Aya',
        'signature_data' => $signature,
        'latitude' => 5.3364, 'longitude' => -4.0267,
        'remarks' => 'Livré au magasin, 2 palettes',
    ])->assertCreated()->json();

    expect($pod['signature_data'])->toBe($signature)
        ->and((float) $pod['latitude'])->toBe(5.3364)
        ->and(DB::table('missions')->where('id', $missionId)->value('status'))->toBe('delivered');
});

it('refuse une preuve de livraison sur une mission qui ne roule pas', function (): void {
    $ids = seedCore();
    $token = tokenFor($ids['user_admin']);
    $missionId = $this->withToken($token)->postJson('/api/v1/missions', ['type' => 'delivery'])->json('id');

    $this->withToken($token)->postJson("/api/v1/missions/{$missionId}/pod", ['recipient_name' => 'Konan Aya'])
        ->assertNotFound();
});

/** Mission livrée et signée, rattachée à un dossier — socle des tests de bon de livraison. */
function seedDeliveredMission(array $ids, string $shipmentId, string $carrierId): string
{
    $token = tokenFor($ids['user_admin']);
    $truckId = test()->withToken($token)->postJson('/api/v1/fleet/trucks', [
        'plate_number' => '1234AB01', 'carrier_party_id' => $carrierId,
    ])->json('id');
    $missionId = test()->withToken($token)->postJson('/api/v1/missions', [
        'shipment_id' => $shipmentId, 'type' => 'delivery', 'carrier_party_id' => $carrierId,
        'truck_id' => $truckId, 'stops' => [['label' => "Port d'Abidjan"], ['label' => 'Entrepôt Yopougon']],
    ])->json('id');
    test()->withToken($token)->postJson("/api/v1/missions/{$missionId}/transition", ['status' => 'in_progress']);
    test()->withToken($token)->postJson("/api/v1/missions/{$missionId}/pod", [
        'recipient_name' => 'Konan Aya',
        'signature_data' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUg==',
        'latitude' => 5.3364, 'longitude' => -4.0267,
    ]);

    return $missionId;
}

it('édite un bon de livraison signé une fois la mission livrée', function (): void {
    $ids = seedCore();
    $shipmentId = seedShipmentFor($ids, $ids['client'], 'IMP-2026-00001');
    $missionId = seedDeliveredMission($ids, $shipmentId, seedCarrier($ids));

    $response = $this->withToken(tokenFor($ids['user_admin']))
        ->get("/api/v1/missions/{$missionId}/delivery-note")->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('bon-livraison-mis-');
});

it('refuse le bon de livraison tant que rien n\'a été signé', function (): void {
    $ids = seedCore();
    $token = tokenFor($ids['user_admin']);
    $missionId = $this->withToken($token)->postJson('/api/v1/missions', ['type' => 'delivery'])->json('id');

    $this->withToken($token)->getJson("/api/v1/missions/{$missionId}/delivery-note")
        ->assertStatus(422)->assertJsonPath('error_code', 'mission.pod_missing');
});

it('laisse le client télécharger le bon de livraison de son dossier', function (): void {
    $ids = seedCore();
    $shipmentId = seedShipmentFor($ids, $ids['client'], 'IMP-2026-00001');
    $missionId = seedDeliveredMission($ids, $shipmentId, seedCarrier($ids));

    $portalToken = portalTokenFor($ids['portal']);
    freshAuth();
    $listed = $this->withToken($portalToken)
        ->getJson("/api/v1/portal/shipments/{$shipmentId}/delivery-notes")->assertOk()->json('data');
    $pdf = $this->withToken($portalToken)->get("/api/v1/portal/missions/{$missionId}/delivery-note")->assertOk();

    expect($listed)->toHaveCount(1)
        ->and($listed[0]['recipient_name'])->toBe('Konan Aya')
        ->and($pdf->headers->get('content-type'))->toContain('application/pdf');
});

it('cache le bon de livraison d\'un autre client', function (): void {
    $ids = seedCore();
    $otherClient = (string) Str::uuid7();
    DB::table('parties')->insert([
        'id' => $otherClient, 'tenant_id' => $ids['tenant'], 'type' => 'client', 'code' => 'CLI9',
        'name' => 'Client Neuf', 'payment_terms_days' => 30, 'notification_prefs' => '{}', 'tags' => '[]',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $shipmentId = seedShipmentFor($ids, $otherClient, 'IMP-2026-00009');
    $missionId = seedDeliveredMission($ids, $shipmentId, seedCarrier($ids));

    $portalToken = portalTokenFor($ids['portal']);
    freshAuth();
    $this->withToken($portalToken)->getJson("/api/v1/portal/missions/{$missionId}/delivery-note")->assertNotFound();
    expect($this->withToken($portalToken)->getJson("/api/v1/portal/shipments/{$shipmentId}/delivery-notes")->json('data'))
        ->toBeEmpty();
});
