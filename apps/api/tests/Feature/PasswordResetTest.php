<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/** Crée un enregistrement de reset pour l'admin démo, avec âge paramétrable. */
function seedResetToken(int $ageMinutes): array
{
    $ids = seedCore();
    $email = 'admin@test.local';
    $plainToken = str_repeat('a', 64);
    DB::table('password_reset_tokens')->insert([
        'tenant_id' => $ids['tenant'],
        'email' => $email,
        'token' => Hash::make($plainToken),
        'created_at' => now()->subMinutes($ageMinutes),
    ]);

    return [$email, $plainToken];
}

it('accepte un token de reset récent et change le mot de passe', function (): void {
    [$email, $token] = seedResetToken(ageMinutes: 5);

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => $email,
        'token' => $token,
        'password' => 'Str0ng!NewP@ss',
    ])->assertOk()->assertJsonPath('reset', true);

    // Token consommé
    expect(DB::table('password_reset_tokens')->where('email', $email)->exists())->toBeFalse();
});

it('refuse un token de reset expiré (> 60 min) — régression Carbon 3 diffInMinutes signé', function (): void {
    [$email, $token] = seedResetToken(ageMinutes: 61);

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => $email,
        'token' => $token,
        'password' => 'Str0ng!NewP@ss',
    ])->assertUnprocessable()->assertJsonPath('errors.token.0', 'Lien invalide ou expiré.');

    // Le mot de passe n'a PAS été changé (le token n'aurait jamais dû passer)
    expect(DB::table('password_reset_tokens')->where('email', $email)->exists())->toBeTrue();
});

it('refuse un mauvais token', function (): void {
    [$email] = seedResetToken(ageMinutes: 5);

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => $email,
        'token' => str_repeat('b', 64),
        'password' => 'Str0ng!NewP@ss',
    ])->assertUnprocessable();
});
