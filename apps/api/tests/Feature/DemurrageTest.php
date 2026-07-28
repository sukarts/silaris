<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Silaris\Modules\Ocean\Application\Service\FreeTimeTracker;

uses(RefreshDatabase::class);

/** Dossier import avec connaissement maître, conteneur affecté et franchise négociée. */
function seedFreeTimeShipment(array $ids, int $freeDays, string $direction = 'import'): array
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
            'free_time_days' => $freeDays, 'created_at' => now(), 'updated_at' => now(),
        ]);
    } else {
        DB::table('bills_of_lading')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $ids['tenant'], 'shipment_id' => $shipmentId,
            'type' => 'master', 'number' => 'MEDUJ2260417', 'status' => 'issued',
            'shipper' => '{}', 'consignee' => '{}', 'free_time_days' => $freeDays,
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

it('fait courir la franchise depuis le déchargement à l\'import', function (): void {
    $ids = seedCore();
    [$shipmentId, $assignmentId] = seedFreeTimeShipment($ids, freeDays: 7);

    // Le suivi annonce le déchargement il y a cinq jours.
    app(FreeTimeTracker::class)->recordMilestone(
        $shipmentId, 'MSNU9682848', 'DISC', Carbon::today()->subDays(5),
    );

    $assignment = DB::table('container_assignments')->where('id', $assignmentId)->first();
    expect($assignment->free_time_days)->toBe(7)
        ->and(Carbon::parse($assignment->free_time_ends_at)->toDateString())
        ->toBe(Carbon::today()->addDays(2)->toDateString());
});

it('fait courir la franchise depuis la sortie du vide à l\'export', function (): void {
    $ids = seedCore();
    [$shipmentId, $assignmentId] = seedFreeTimeShipment($ids, freeDays: 10, direction: 'export');

    app(FreeTimeTracker::class)->recordMilestone(
        $shipmentId, 'MSNU9682848', 'GTOT', Carbon::today()->subDays(3),
    );

    expect(Carbon::parse(DB::table('container_assignments')->where('id', $assignmentId)->value('free_time_ends_at'))->toDateString())
        ->toBe(Carbon::today()->addDays(7)->toDateString());
});

it('classe les conteneurs par urgence et compte les dépassements', function (): void {
    $ids = seedCore();
    $token = tokenFor($ids['user_admin']);
    [$shipmentId] = seedFreeTimeShipment($ids, freeDays: 7);
    // Déchargé il y a dix jours pour sept de franchise : trois jours de retard.
    app(FreeTimeTracker::class)->recordMilestone($shipmentId, 'MSNU9682848', 'DISC', Carbon::today()->subDays(10));

    $response = $this->withToken($token)->getJson('/api/v1/demurrage')->assertOk()->json();

    expect($response['data'])->toHaveCount(1)
        ->and($response['data'][0]['days_remaining'])->toBe(-3)
        ->and($response['data'][0]['severity'])->toBe('overdue')
        ->and($response['summary']['overdue'])->toBe(1);
});

it('sort de la liste dès le conteneur restitué', function (): void {
    $ids = seedCore();
    $token = tokenFor($ids['user_admin']);
    [$shipmentId] = seedFreeTimeShipment($ids, freeDays: 7);
    app(FreeTimeTracker::class)->recordMilestone($shipmentId, 'MSNU9682848', 'DISC', Carbon::today()->subDays(10));

    expect($this->withToken($token)->getJson('/api/v1/demurrage')->json('data'))->toHaveCount(1);

    // Le vide rendu, plus rien ne court.
    app(FreeTimeTracker::class)->recordMilestone($shipmentId, 'MSNU9682848', 'RETU', Carbon::today());

    expect($this->withToken($token)->getJson('/api/v1/demurrage')->json('data'))->toBeEmpty();
});

it('recalcule les échéances quand la franchise négociée change', function (): void {
    $ids = seedCore();
    $token = tokenFor($ids['user_admin']);
    [$shipmentId, $assignmentId] = seedFreeTimeShipment($ids, freeDays: 7);
    app(FreeTimeTracker::class)->recordMilestone($shipmentId, 'MSNU9682848', 'DISC', Carbon::today()->subDays(5));

    $this->withToken($token)->patchJson('/api/v1/demurrage/free-time', [
        'shipment_id' => $shipmentId, 'free_time_days' => 14,
    ])->assertOk()->assertJsonPath('containers_refreshed', 1);

    expect(Carbon::parse(DB::table('container_assignments')->where('id', $assignmentId)->value('free_time_ends_at'))->toDateString())
        ->toBe(Carbon::today()->addDays(9)->toDateString());
});

it('ne repousse pas une échéance déjà courue si le relevé est rejoué', function (): void {
    $ids = seedCore();
    [$shipmentId, $assignmentId] = seedFreeTimeShipment($ids, freeDays: 7);
    $tracker = app(FreeTimeTracker::class);

    $tracker->recordMilestone($shipmentId, 'MSNU9682848', 'DISC', Carbon::today()->subDays(5));
    $first = DB::table('container_assignments')->where('id', $assignmentId)->value('free_time_ends_at');

    // Un agrégateur peut renvoyer le même mouvement avec un horodatage corrigé.
    $tracker->recordMilestone($shipmentId, 'MSNU9682848', 'DISC', Carbon::today());

    expect(DB::table('container_assignments')->where('id', $assignmentId)->value('free_time_ends_at'))->toBe($first);
});

it('refuse la franchise import sans connaissement maître', function (): void {
    $ids = seedCore();
    $shipmentId = seedShipmentFor($ids, $ids['client'], 'IMP-2026-00002');

    $this->withToken(tokenFor($ids['user_admin']))
        ->patchJson('/api/v1/demurrage/free-time', ['shipment_id' => $shipmentId, 'free_time_days' => 7])
        ->assertStatus(422)->assertJsonPath('message', "Aucun connaissement maître sur ce dossier : la franchise import s'y rattache.");
});

it('alerte l\'exploitant et le client avant l\'échéance, une seule fois', function (): void {
    $ids = seedCore();
    [$shipmentId] = seedFreeTimeShipment($ids, freeDays: 7);
    // Déchargé il y a cinq jours : l'échéance tombe dans deux jours.
    app(FreeTimeTracker::class)->recordMilestone($shipmentId, 'MSNU9682848', 'DISC', Carbon::today()->subDays(5));

    $this->artisan('demurrage:alert')->assertSuccessful();

    // Le client reçoit une notification, l'exploitant voit l'alerte au dossier.
    expect(DB::table('notifications')->where('event_type', 'demurrage_warning')->count())->toBe(1)
        ->and(DB::table('shipment_events')->where('shipment_id', $shipmentId)->where('type', 'system')->count())->toBe(1);

    // Relancée le lendemain, la commande ne réémet pas le même palier.
    $this->artisan('demurrage:alert')->assertSuccessful();
    expect(DB::table('notifications')->where('event_type', 'demurrage_warning')->count())->toBe(1);
});

it('réalerte quand le conteneur bascule en dépassement', function (): void {
    $ids = seedCore();
    [$shipmentId] = seedFreeTimeShipment($ids, freeDays: 7);
    app(FreeTimeTracker::class)->recordMilestone($shipmentId, 'MSNU9682848', 'DISC', Carbon::today()->subDays(5));
    $this->artisan('demurrage:alert')->assertSuccessful();

    // Dix jours plus tard, la franchise est dépassée : c'est un autre palier.
    Carbon::setTestNow(Carbon::now()->addDays(10));
    $this->artisan('demurrage:alert')->assertSuccessful();
    Carbon::setTestNow();

    expect(DB::table('notifications')->where('event_type', 'demurrage_warning')->count())->toBe(2)
        ->and(DB::table('shipment_events')->where('type', 'system')->latest('occurred_at')->value('title'))
        ->toContain('Surestaries en cours');
});
