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
use Silaris\Modules\Identity\Application\Service\PasswordPolicy;
use Silaris\Modules\Identity\Application\Service\SessionLogger;
use Silaris\Modules\Identity\Infrastructure\Persistence\Model\UserModel;
use Silaris\Modules\Shared\Domain\Exception\AmbiguousAccount;
use Silaris\Modules\Shared\Infrastructure\Tenancy\GuestTenantResolver;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Silaris\Modules\Tenancy\Infrastructure\Persistence\Model\BranchModel;

class AuthController
{
    private const TOKEN_NAME = 'silaris-app';

    private const MFA_CHALLENGE_TTL = 300; // 5 min

    public function __construct(private readonly SessionLogger $sessions) {}

    /** POST /auth/login — étape 1 : identifiants. MFA actif → challenge. */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $tenantId = app(GuestTenantResolver::class)->resolve($request);
        // Recherche pré-auth : cross-tenant délibéré → connexion système (échappe à la RLS).
        $matches = UserModel::on(config('database.system_connection'))->withoutGlobalScopes()
            ->where('email', strtolower($credentials['email']))
            ->where('is_active', true)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->limit(2)
            ->get();

        if ($matches->count() > 1) {
            throw AmbiguousAccount::make();
        }
        $user = $matches->first();
        // Compte identifié → contexte tenant posé : les écritures (login_failed, last_login,
        // sessions_log, tokens) respectent désormais la RLS.
        if ($user !== null) {
            app(TenantContext::class)->set($user->tenant_id);
            $user->setConnection(config('database.default'));
        }

        if ($user === null || ! Hash::check($credentials['password'], $user->password_hash)) {
            if ($user !== null) {
                $this->sessions->log($user->tenant_id, $user->id, 'login_failed', $request);
            }
            throw ValidationException::withMessages(['email' => ['Identifiants invalides.']]);
        }

        if ($user->mfa_enabled) {
            $challenge = Str::random(64);
            Cache::put("mfa_challenge:{$challenge}", $user->id, self::MFA_CHALLENGE_TTL);

            return response()->json(['mfa_required' => true, 'challenge' => $challenge]);
        }

        return $this->issueToken($user, $request);
    }

    /** POST /auth/mfa/verify — étape 2 : code TOTP ou code de récupération. */
    public function verifyMfa(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'size:64'],
            'code' => ['required', 'string', 'min:6', 'max:16'],
        ]);

        $userId = Cache::get("mfa_challenge:{$data['challenge']}");
        if ($userId === null) {
            throw ValidationException::withMessages(['challenge' => ['Challenge expiré — reconnectez-vous.']]);
        }

        $user = UserModel::withoutGlobalScopes()->findOrFail($userId);

        if (! $this->checkTotp($user, $data['code']) && ! $this->consumeRecoveryCode($user, $data['code'])) {
            $this->sessions->log($user->tenant_id, $user->id, 'mfa_failed', $request);
            throw ValidationException::withMessages(['code' => ['Code invalide.']]);
        }

        Cache::forget("mfa_challenge:{$data['challenge']}");

        return $this->issueToken($user, $request);
    }

    /** GET /auth/me — profil + permissions effectives (RBAC frontend). */
    public function me(Request $request): JsonResponse
    {
        /** @var UserModel $user */
        $user = $request->user();
        $user->load(['roles.permissions:key', 'branches:id,code,name,company_id', 'branches.company:id,legal_name,code']);

        return response()->json([
            'id' => $user->id,
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'locale' => $user->locale,
            'mfa_enabled' => $user->mfa_enabled,
            'roles' => $user->roles->map(fn ($role) => ['key' => $role->key, 'name' => $role->name]),
            'permissions' => $user->roles->flatMap(fn ($role) => $role->permissions->pluck('key'))->unique()->sort()->values(),
            // Les agences de rattachement portent leur société : c'est le
            // périmètre dans lequel l'utilisateur ouvre ses dossiers, sans
            // qu'il ait besoin d'accéder à l'administration.
            'branches' => $user->branches->map(fn (BranchModel $branch) => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
                'company_id' => $branch->company_id,
                'company_name' => $branch->company?->legal_name,
            ]),
            'must_change_password' => $user->password_changed_at < now()->subYears(5),
        ]);
    }

    /** POST /auth/logout — révoque le token courant. */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->user()->currentAccessToken()->delete();
        $this->sessions->log($user->tenant_id, $user->id, 'logout', $request);

        return response()->json(['logged_out' => true]);
    }

    /** POST /auth/logout-all — révoque tous les appareils. */
    public function logoutAll(Request $request): JsonResponse
    {
        $user = $request->user();
        $count = $user->tokens()->delete();
        $this->sessions->log($user->tenant_id, $user->id, 'token_revoked', $request);

        return response()->json(['revoked_tokens' => $count]);
    }

    /** POST /auth/change-password */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', PasswordPolicy::rule(), 'different:current_password'],
        ]);

        /** @var UserModel $user */
        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password_hash)) {
            throw ValidationException::withMessages(['current_password' => ['Mot de passe actuel incorrect.']]);
        }

        $user->update([
            'password_hash' => Hash::make($data['new_password']),
            'password_changed_at' => now(),
        ]);
        // Révocation des autres sessions (l'actuelle reste valide).
        $user->tokens()->where('id', '<>', $user->currentAccessToken()->id)->delete();

        return response()->json(['changed' => true]);
    }

    private function issueToken(UserModel $user, Request $request): JsonResponse
    {
        $token = $user->createToken(self::TOKEN_NAME, ['*'], now()->addMinutes((int) config('sanctum.expiration', 720)));
        $user->update(['last_login_at' => now()]);
        $this->sessions->log($user->tenant_id, $user->id, 'login', $request);

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => ['id' => $user->id, 'email' => $user->email, 'first_name' => $user->first_name, 'last_name' => $user->last_name],
        ]);
    }

    private function checkTotp(UserModel $user, string $code): bool
    {
        if ($user->mfa_secret === null || strlen($code) > 8) {
            return false;
        }

        return (bool) app(Google2FA::class)->verifyKey($user->mfa_secret, $code, window: 1);
    }

    private function consumeRecoveryCode(UserModel $user, string $code): bool
    {
        $hashes = $user->mfa_recovery_codes ?? [];
        foreach ($hashes as $index => $hash) {
            if (Hash::check(strtoupper($code), $hash)) {
                unset($hashes[$index]);
                $user->update(['mfa_recovery_codes' => array_values($hashes)]);

                return true;
            }
        }

        return false;
    }
}
