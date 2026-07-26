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

it('génère un code client automatique et crée contact + adresse imbriqués', function (): void {
    $ids = seedCore();
    DB::table('countries')->insertOrIgnore(['code2' => 'CI', 'code3' => 'CIV', 'name_fr' => "Côte d'Ivoire", 'name_en' => 'Ivory Coast', 'created_at' => now(), 'updated_at' => now()]);

    $response = $this->withToken(tokenFor($ids['user_admin']))->postJson('/api/v1/parties', [
        'type' => 'client', 'kind' => 'company', 'name' => 'Nouvelle Société SARL',
        'tax_id' => 'CI-ABJ-2026-B-0001', 'industry' => 'Négoce',
        'contact' => ['name' => 'Awa Contact', 'email' => 'awa@nouvelle.ci'],
        'address' => ['line1' => 'Bd du Port', 'city' => 'Abidjan', 'country_code' => 'CI'],
    ]);

    $response->assertCreated();
    expect($response->json('code'))->toMatch('/^CLI-\d{4}$/')
        ->and($response->json('industry'))->toBe('Négoce')
        ->and($response->json('contacts.0.email'))->toBe('awa@nouvelle.ci')
        ->and($response->json('addresses.0.city'))->toBe('Abidjan');

    // Deuxième création sans code → séquence suivante, jamais de collision.
    $second = $this->withToken(tokenFor($ids['user_admin']))->postJson('/api/v1/parties', [
        'type' => 'client', 'name' => 'Autre Société',
    ])->assertCreated()->json('code');
    expect($second)->not->toBe($response->json('code'))->toMatch('/^CLI-\d{4}$/');
});
