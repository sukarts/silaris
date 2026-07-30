<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Silaris\Modules\Ocean\Application\Service\FreeTimeTracker;

uses(RefreshDatabase::class);

/**
 * Dossier avec son document porteur, un conteneur affecté, et les deux
 * franchises négociées — surestaries (au terminal) et détention (chez le client).
 */
function seedFreeTimeShipment(array $ids, int $demurrageDays, int $detentionDays, string $direction = 'import'): array
{
    $shipmentId = seedShipmentFor($ids, $ids['client'], 'IMP-2026-00001');
    DB::table('shipments')->where('id', $shipmentId)->update(['direction' => $direction]);

    if ($direction === 'export') {
        $carrierId = (string) Str::uuid7();
        DB::table('carriers')->insert([
            'id' => $carrierId, 'scac' => 'MSCU', 'name' => 'MSC', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('bookings')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $ids['tenant'], 'shipment_id' => $shipmentId,
            'carrier_id' => $carrierId, 'booking_number' => 'EBKG12345678', 'status' => 'requested',
            'demurrage_free_days' => $demurrageDays, 'detention_free_days' => $detentionDays,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    } else {
        DB::table('bills_of_lading')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $ids['tenant'], 'shipment_id' => $shipmentId,
            'type' => 'master', 'number' => 'MEDUJ2260417', 'status' => 'issued',
            'shipper' => '{}', 'consignee' => '{}',
            'demurrage_free_days' => $demurrageDays, 'detention_free_days' => $detentionDays,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $containerId = seedContainer($ids, 'MSNU9682848');
    $assignmentId = (string) Str::uuid7();
    DB::table('container_assignments')->insert([
        'id' => $assignmentId, 'tenant_id' => $ids['tenant'], 'container_id' => $containerId,
        'shipment_id' => $shipmentId, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$shipmentId, $assignmentId];
}

it('à l\'import, la surestarie court du déchargement, la détention de la sortie du port', function (): void {
    $ids = seedCore();
    [$shipmentId, $assignmentId] = seedFreeTimeShipment($ids, demurrageDays: 5, detentionDays: 10);
    $tracker = app(FreeTimeTracker::class);

    // Déchargé il y a deux jours : la surestarie court, la détention pas encore.
    $tracker->recordMilestone($shipmentId, 'MSNU9682848', 'DISC', Carbon::today()->subDays(2));

    $row = DB::table('container_assignments')->where('id', $assignmentId)->first();
    expect(Carbon::parse($row->demurrage_ends_at)->toDateString())->toBe(Carbon::today()->addDays(3)->toDateString())
        ->and($row->detention_ends_at)->toBeNull();

    // Sorti du port aujourd'hui : la surestarie s'arrête, la détention démarre.
    $tracker->recordMilestone($shipmentId, 'MSNU9682848', 'GTOT', Carbon::today());
    $row = DB::table('container_assignments')->where('id', $assignmentId)->first();
    expect(Carbon::parse($row->detention_ends_at)->toDateString())->toBe(Carbon::today()->addDays(10)->toDateString());
});

it('liste surestarie et détention comme deux échéances distinctes', function (): void {
    $ids = seedCore();
    $token = tokenFor($ids['user_admin']);
    [$shipmentId] = seedFreeTimeShipment($ids, demurrageDays: 7, detentionDays: 14);
    $tracker = app(FreeTimeTracker::class);

    // Déchargé : seule la surestarie court.
    $tracker->recordMilestone($shipmentId, 'MSNU9682848', 'DISC', Carbon::today()->subDays(6));
    $data = $this->withToken($token)->getJson('/api/v1/demurrage?within_days=30')->assertOk()->json('data');
    expect($data)->toHaveCount(1)->and($data[0]['kind'])->toBe('demurrage');

    // Sorti du port : la surestarie s'arrête, la détention court.
    freshAuth();
    $tracker->recordMilestone($shipmentId, 'MSNU9682848', 'GTOT', Carbon::today()->subDays(2));
    $data = $this->withToken($token)->getJson('/api/v1/demurrage?within_days=30')->assertOk()->json('data');
    expect($data)->toHaveCount(1)->and($data[0]['kind'])->toBe('detention');
});

it('classe par urgence et compte le dépassement de surestaries', function (): void {
    $ids = seedCore();
    $token = tokenFor($ids['user_admin']);
    [$shipmentId] = seedFreeTimeShipment($ids, demurrageDays: 7, detentionDays: 14);
    // Déchargé il y a dix jours, sept de franchise surestaries : trois jours de retard.
    app(FreeTimeTracker::class)->recordMilestone($shipmentId, 'MSNU9682848', 'DISC', Carbon::today()->subDays(10));

    $response = $this->withToken($token)->getJson('/api/v1/demurrage')->assertOk()->json();

    expect($response['data'])->toHaveCount(1)
        ->and($response['data'][0]['days_remaining'])->toBe(-3)
        ->and($response['data'][0]['severity'])->toBe('overdue')
        ->and($response['summary']['overdue'])->toBe(1);
});

it('la détention sort de la liste dès le vide restitué', function (): void {
    $ids = seedCore();
    $token = tokenFor($ids['user_admin']);
    [$shipmentId] = seedFreeTimeShipment($ids, demurrageDays: 3, detentionDays: 7);
    $tracker = app(FreeTimeTracker::class);
    $tracker->recordMilestone($shipmentId, 'MSNU9682848', 'GTOT', Carbon::today()->subDays(10));

    expect($this->withToken($token)->getJson('/api/v1/demurrage')->json('data'))->toHaveCount(1);

    freshAuth();
    $tracker->recordMilestone($shipmentId, 'MSNU9682848', 'RETU', Carbon::today());
    expect($this->withToken($token)->getJson('/api/v1/demurrage')->json('data'))->toBeEmpty();
});

it('recalcule les échéances quand les franchises négociées changent', function (): void {
    $ids = seedCore();
    $token = tokenFor($ids['user_admin']);
    [$shipmentId, $assignmentId] = seedFreeTimeShipment($ids, demurrageDays: 5, detentionDays: 10);
    app(FreeTimeTracker::class)->recordMilestone($shipmentId, 'MSNU9682848', 'DISC', Carbon::today()->subDays(3));

    $this->withToken($token)->patchJson('/api/v1/demurrage/free-time', [
        'shipment_id' => $shipmentId, 'demurrage_free_days' => 8, 'detention_free_days' => 14,
    ])->assertOk()->assertJsonPath('containers_refreshed', 1);

    expect(Carbon::parse(DB::table('container_assignments')->where('id', $assignmentId)->value('demurrage_ends_at'))->toDateString())
        ->toBe(Carbon::today()->addDays(5)->toDateString());
});

it('ne repousse pas une échéance déjà courue si le relevé est rejoué', function (): void {
    $ids = seedCore();
    [$shipmentId, $assignmentId] = seedFreeTimeShipment($ids, demurrageDays: 7, detentionDays: 14);
    $tracker = app(FreeTimeTracker::class);

    $tracker->recordMilestone($shipmentId, 'MSNU9682848', 'DISC', Carbon::today()->subDays(5));
    $first = DB::table('container_assignments')->where('id', $assignmentId)->value('demurrage_ends_at');

    $tracker->recordMilestone($shipmentId, 'MSNU9682848', 'DISC', Carbon::today());

    expect(DB::table('container_assignments')->where('id', $assignmentId)->value('demurrage_ends_at'))->toBe($first);
});

it('refuse la franchise import sans connaissement maître', function (): void {
    $ids = seedCore();
    $shipmentId = seedShipmentFor($ids, $ids['client'], 'IMP-2026-00002');

    $this->withToken(tokenFor($ids['user_admin']))
        ->patchJson('/api/v1/demurrage/free-time', ['shipment_id' => $shipmentId, 'detention_free_days' => 7])
        ->assertStatus(422)->assertJsonPath('message', "Aucun connaissement maître sur ce dossier : la franchise import s'y rattache.");
});

it('alerte séparément sur la surestarie et sur la détention, exploitant et client', function (): void {
    $ids = seedCore();
    [$shipmentId] = seedFreeTimeShipment($ids, demurrageDays: 5, detentionDays: 10);
    $tracker = app(FreeTimeTracker::class);

    // Déchargé il y a trois jours : la surestarie expire dans deux jours.
    $tracker->recordMilestone($shipmentId, 'MSNU9682848', 'DISC', Carbon::today()->subDays(3));
    $this->artisan('demurrage:alert')->assertSuccessful();
    expect(DB::table('notifications')->where('event_type', 'demurrage_warning')->count())->toBe(1);

    // Sorti du port il y a huit jours : la détention expire dans deux jours.
    $tracker->recordMilestone($shipmentId, 'MSNU9682848', 'GTOT', Carbon::today()->subDays(8));
    $this->artisan('demurrage:alert')->assertSuccessful();
    expect(DB::table('notifications')->where('event_type', 'detention_warning')->count())->toBe(1)
        // L'exploitant voit les deux alertes au dossier.
        ->and(DB::table('shipment_events')->where('shipment_id', $shipmentId)->where('type', 'system')->count())->toBeGreaterThanOrEqual(2);
});
