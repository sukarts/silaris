<?php

declare(strict_types=1);

namespace Silaris\Modules\Identity\Interface\Http\Controller;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Silaris\Modules\Identity\Application\Service\PasswordPolicy;
use Silaris\Modules\Identity\Infrastructure\Persistence\Model\UserModel;
use Silaris\Modules\Notifications\Infrastructure\Mail\PasswordResetMail;
use Silaris\Modules\Shared\Infrastructure\Tenancy\GuestTenantResolver;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Throwable;

class PasswordResetController
{
    /** POST /auth/forgot-password — réponse identique que l'email existe ou non (anti-énumération). */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $email = strtolower($data['email']);

        $tenantId = app(GuestTenantResolver::class)->resolve($request);
        $user = UserModel::on(config('database.system_connection'))->withoutGlobalScopes()
            ->where('email', $email)
            ->where('is_active', true)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->limit(2)->get();
        // Silencieux si aucun ou plusieurs comptes (anti-énumération + jamais de ciblage arbitraire).
        if ($user->count() === 1) {
            $account = $user->first();
            $token = Str::random(64);
            DB::table('password_reset_tokens')->updateOrInsert(
                ['tenant_id' => $account->tenant_id, 'email' => $email],
                ['token' => Hash::make($token), 'created_at' => now()],
            );
            try {
                Mail::to($email)->send(new PasswordResetMail($email, $token));
            } catch (Throwable $e) {
                // Réponse inchangée (anti-énumération) — l'échec d'envoi est tracé côté ops.
                Log::error("Échec envoi email de reset pour {$email} : {$e->getMessage()}");
            }
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

        $tenantId = app(GuestTenantResolver::class)->resolve($request);
        $candidates = UserModel::on(config('database.system_connection'))->withoutGlobalScopes()
            ->where('email', $email)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->limit(2)->get();
        if ($candidates->count() !== 1) {
            throw ValidationException::withMessages(['token' => ['Lien invalide ou expiré.']]);
        }
        $resolvedTenantId = $candidates->first()->tenant_id;
        app(TenantContext::class)->set($resolvedTenantId); // écritures RLS-compatibles

        $record = DB::table('password_reset_tokens')
            ->where('tenant_id', $resolvedTenantId)->where('email', $email)->first();
        // Carbon 3 : diffInMinutes() est signé par défaut ; created_at étant dans le passé,
        // l'expiration doit se mesurer en valeur absolue (sinon jamais déclenchée).
        $expired = $record !== null && Carbon::parse($record->created_at)->addMinutes(60)->isPast();
        if ($record === null || $expired || ! Hash::check($data['token'], $record->token)) {
            throw ValidationException::withMessages(['token' => ['Lien invalide ou expiré.']]);
        }

        $user = $candidates->first();
        $user->setConnection(config('database.default'));
        $user->update(['password_hash' => Hash::make($data['password']), 'password_changed_at' => now()]);
        $user->tokens()->delete(); // révocation totale
        DB::table('password_reset_tokens')->where('tenant_id', $resolvedTenantId)->where('email', $email)->delete();

        return response()->json(['reset' => true]);
    }
}
