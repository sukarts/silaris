<?php

declare(strict_types=1);

namespace Silaris\Modules\Identity\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Silaris\Modules\Identity\Application\Service\PasswordPolicy;
use Silaris\Modules\Identity\Infrastructure\Persistence\Model\UserModel;

class PasswordResetController
{
    /** POST /auth/forgot-password — réponse identique que l'email existe ou non (anti-énumération). */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $email = strtolower($data['email']);

        $user = UserModel::withoutGlobalScopes()->where('email', $email)->where('is_active', true)->first();
        if ($user !== null) {
            $token = Str::random(64);
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $email],
                ['token' => Hash::make($token), 'created_at' => now()],
            );
            // Envoi email réel : module Notifications (Étape 15). En dev : log.
            Log::info("Password reset token for {$email}: {$token}");
        }

        return response()->json(['sent' => true]);
    }

    /** POST /auth/reset-password */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', PasswordPolicy::rule()],
        ]);
        $email = strtolower($data['email']);

        $record = DB::table('password_reset_tokens')->where('email', $email)->first();
        if ($record === null || now()->diffInMinutes($record->created_at) > 60 || ! Hash::check($data['token'], $record->token)) {
            throw ValidationException::withMessages(['token' => ['Lien invalide ou expiré.']]);
        }

        $user = UserModel::withoutGlobalScopes()->where('email', $email)->firstOrFail();
        $user->update(['password_hash' => Hash::make($data['password']), 'password_changed_at' => now()]);
        $user->tokens()->delete(); // révocation totale
        DB::table('password_reset_tokens')->where('email', $email)->delete();

        return response()->json(['reset' => true]);
    }
}
