<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Silaris\Modules\Notifications\Infrastructure\Mail\NotificationMail;
use Silaris\Modules\Notifications\Infrastructure\Mail\PasswordResetMail;
use Silaris\Modules\Notifications\Infrastructure\Mail\UserInvitationMail;

uses(RefreshDatabase::class);

/** Dossier minimal + événement outbox non publié, prêt pour outbox:process. */
function seedOutboxEvent(array $ids, string $eventType, array $payload): string
{
    $shipmentId = (string) Str::uuid7();
    DB::table('shipments')->insert([
        'id' => $shipmentId, 'tenant_id' => $ids['tenant'],
        'reference' => 'TST-2026-00001', 'client_id' => $ids['client'],
        'branch_id' => $ids['branch'], 'company_id' => $ids['company'],
        'agent_id' => $ids['user_transit_agent'], 'direction' => 'import', 'mode' => 'sea_fcl',
        'status' => 'departure', 'workflow_definition_id' => $ids['workflow'],
        'incoterm_code' => 'CIF', 'origin_locode' => 'CNSHA', 'destination_locode' => 'CIABJ',
        'eta' => now()->addDays(20), 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('outbox_events')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $ids['tenant'],
        'aggregate_type' => 'shipment', 'aggregate_id' => $shipmentId,
        'event_type' => $eventType, 'payload' => json_encode($payload),
        'occurred_at' => now(),
    ]);

    return $shipmentId;
}

it('envoie un vrai email de réinitialisation de mot de passe', function (): void {
    Mail::fake();
    seedCore();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'admin@test.local'])
        ->assertOk()->assertJsonPath('sent', true);

    Mail::assertSent(PasswordResetMail::class, fn ($mail) => $mail->hasTo('admin@test.local'));
});

it("envoie l'invitation email à la création d'un utilisateur (mot de passe non exposé)", function (): void {
    Mail::fake();
    $ids = seedCore();
    $roleId = DB::table('roles')->whereNull('tenant_id')->where('key', 'transit_agent')->value('id');

    $response = $this->withToken(tokenFor($ids['user_admin']))->postJson('/api/v1/admin/users', [
        'email' => 'nouveau@test.local', 'first_name' => 'Awa', 'last_name' => 'Diop',
        'role_ids' => [$roleId], 'branch_ids' => [$ids['branch']],
    ])->assertCreated()->assertJsonPath('invitation_sent', true);

    expect($response->json('temporary_password'))->toBeNull();
    Mail::assertSent(UserInvitationMail::class, fn ($mail) => $mail->hasTo('nouveau@test.local'));
});

it('outbox:process transforme un retard détecté en notification email au client', function (): void {
    Mail::fake();
    $ids = seedCore();
    seedOutboxEvent($ids, 'shipment.delay_detected', ['delay_hours' => 36, 'new_eta' => now()->addDays(3)->toIso8601String()]);

    $this->artisan('outbox:process')->assertSuccessful();

    Mail::assertSent(NotificationMail::class, fn ($mail) => $mail->hasTo('client@test.local'));
    expect(DB::table('notifications')->where('event_type', 'delay')->count())->toBe(1)
        ->and(DB::table('notification_deliveries')->where('status', 'sent')->where('recipient', 'client@test.local')->count())->toBe(1)
        ->and(DB::table('outbox_events')->whereNull('published_at')->count())->toBe(0);
});

it('outbox:process notifie le départ (step_advanced → departure) et publie sans effet les étapes non notifiables', function (): void {
    Mail::fake();
    $ids = seedCore();
    seedOutboxEvent($ids, 'shipment.step_advanced', ['from' => 'booking', 'to' => 'departure', 'automatic' => false]);
    DB::table('outbox_events')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $ids['tenant'],
        'aggregate_type' => 'shipment', 'aggregate_id' => (string) Str::uuid7(),
        'event_type' => 'shipment.created', 'payload' => json_encode(['reference' => 'X']),
        'occurred_at' => now(),
    ]);

    $this->artisan('outbox:process')->assertSuccessful();

    Mail::assertSent(NotificationMail::class, 1);
    expect(DB::table('notifications')->where('event_type', 'departure')->count())->toBe(1)
        ->and(DB::table('outbox_events')->whereNull('published_at')->count())->toBe(0);
});

it('respecte une préférence email désactivée : delivery skipped, aucun envoi', function (): void {
    Mail::fake();
    $ids = seedCore();
    DB::table('notification_preferences')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $ids['tenant'],
        'portal_account_id' => $ids['portal'], 'event_type' => 'delay', 'channel' => 'email',
        'enabled' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    seedOutboxEvent($ids, 'shipment.delay_detected', ['delay_hours' => 30, 'new_eta' => now()->addDays(2)->toIso8601String()]);

    $this->artisan('outbox:process')->assertSuccessful();

    Mail::assertNothingSent();
    expect(DB::table('notification_deliveries')->where('status', 'skipped')->count())->toBe(1);
});

it('notifie « facture disponible » sur invoice.validated', function (): void {
    Mail::fake();
    $ids = seedCore();
    $shipmentId = seedOutboxEvent($ids, 'invoice.validated', []);
    // Réécrit le payload avec les données facture (seedOutboxEvent pose un payload générique).
    DB::table('outbox_events')->where('event_type', 'invoice.validated')->update([
        'payload' => json_encode(['number' => 'F-2026-0001', 'total' => '1500000.00', 'currency' => 'XOF', 'client_id' => $ids['client'], 'shipment_id' => $shipmentId]),
    ]);

    $this->artisan('outbox:process')->assertSuccessful();

    Mail::assertSent(NotificationMail::class, fn ($mail) => str_contains($mail->mailSubject, 'F-2026-0001'));
    expect(DB::table('notifications')->where('event_type', 'invoice_available')->count())->toBe(1);
});
