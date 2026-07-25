<?php

declare(strict_types=1);

namespace Silaris\Modules\Identity\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;
use Silaris\Modules\Identity\Infrastructure\Persistence\Model\UserModel;

class MfaController
{
    public function __construct(private readonly Google2FA $google2fa) {}

    /** POST /auth/mfa/enable — génère le secret, retourne l'URI de provisioning (QR côté front). */
    public function enable(Request $request): JsonResponse
    {
        /** @var UserModel $user */
        $user = $request->user();
        if ($user->mfa_enabled) {
            throw ValidationException::withMessages(['mfa' => ['MFA déjà activé.']]);
        }

        $secret = $this->google2fa->generateSecretKey(32);
        Cache::put("mfa_setup:{$user->id}", $secret, 600);

        return response()->json([
            'secret' => $secret,
            'otpauth_uri' => $this->google2fa->getQRCodeUrl('SILARIS', $user->email, $secret),
        ]);
    }

    /** POST /auth/mfa/confirm — vérifie le premier code, active, retourne les codes de récupération (une seule fois). */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'size:6']]);

        /** @var UserModel $user */
        $user = $request->user();
        $secret = Cache::get("mfa_setup:{$user->id}");
        if ($secret === null) {
            throw ValidationException::withMessages(['code' => ['Enrôlement expiré — recommencez.']]);
        }
        if (! $this->google2fa->verifyKey($secret, $data['code'], window: 1)) {
            throw ValidationException::withMessages(['code' => ['Code invalide.']]);
        }

        $recoveryCodes = collect(range(1, 8))->map(fn () => strtoupper(Str::random(4).'-'.Str::random(4)));

        $user->update([
            'mfa_secret' => $secret,
            'mfa_enabled' => true,
            'mfa_recovery_codes' => $recoveryCodes->map(fn ($code) => Hash::make($code))->all(),
        ]);
        Cache::forget("mfa_setup:{$user->id}");

        return response()->json(['enabled' => true, 'recovery_codes' => $recoveryCodes]);
    }

    /** POST /auth/mfa/disable — mot de passe + code TOTP exigés. */
    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        /** @var UserModel $user */
        $user = $request->user();
        if (! Hash::check($data['password'], $user->password_hash)
            || ! $this->google2fa->verifyKey((string) $user->mfa_secret, $data['code'], window: 1)) {
            throw ValidationException::withMessages(['password' => ['Vérification échouée.']]);
        }

        $user->update(['mfa_secret' => null, 'mfa_enabled' => false, 'mfa_recovery_codes' => null]);

        return response()->json(['disabled' => true]);
    }
}
