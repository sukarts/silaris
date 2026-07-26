<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Silaris\Modules\Notifications\Infrastructure\Mail\PortalInvitationMail;

uses(RefreshDatabase::class);

it('invite un client au portail : compte créé + email envoyé', function (): void {
    Mail::fake();
    $ids = seedCore();

    $response = $this->withToken(tokenFor($ids['user_admin']))
        ->postJson("/api/v1/parties/{$ids['client']}/portal-account", ['email' => 'client@exemple.ci']);

    $response->assertCreated()->assertJsonPath('invitation_sent', true)->assertJsonPath('temporary_password', null);
    expect(DB::table('portal_accounts')->where('party_id', $ids['client'])->where('email', 'client@exemple.ci')->exists())->toBeTrue();
    Mail::assertSent(PortalInvitationMail::class, fn ($mail) => $mail->hasTo('client@exemple.ci'));
});

it('réinvite : régénère le mot de passe du compte existant sans doublon', function (): void {
    Mail::fake();
    $ids = seedCore();
    $token = tokenFor($ids['user_admin']);

    $this->withToken($token)->postJson("/api/v1/parties/{$ids['client']}/portal-account", ['email' => 'client@exemple.ci'])->assertCreated();
    $hashBefore = DB::table('portal_accounts')->where('party_id', $ids['client'])->value('password_hash');

    $this->withToken($token)->postJson("/api/v1/parties/{$ids['client']}/portal-account")->assertCreated();

    expect(DB::table('portal_accounts')->where('party_id', $ids['client'])->count())->toBe(1)
        ->and(DB::table('portal_accounts')->where('party_id', $ids['client'])->value('password_hash'))->not->toBe($hashBefore);
    Mail::assertSentCount(2);
});

it('refuse l\'invitation portail sans crm.update', function (): void {
    $ids = seedCore();

    $this->withToken(tokenFor($ids['user_driver']))
        ->postJson("/api/v1/parties/{$ids['client']}/portal-account")
        ->assertForbidden();
});
