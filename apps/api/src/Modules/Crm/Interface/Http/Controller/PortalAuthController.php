<?php

declare(strict_types=1);

namespace Silaris\Modules\Crm\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Silaris\Modules\Crm\Infrastructure\Persistence\Model\PortalAccountModel;
use Silaris\Modules\Shared\Domain\Exception\AmbiguousAccount;
use Silaris\Modules\Shared\Infrastructure\Tenancy\GuestTenantResolver;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;

class PortalAuthController
{
    /** POST /portal/auth/login */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $tenantId = app(GuestTenantResolver::class)->resolve($request);
        $matches = PortalAccountModel::on(config('database.system_connection'))->withoutGlobalScopes()
            ->where('email', strtolower($credentials['email']))
            ->where('is_active', true)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->limit(2)
            ->get();

        if ($matches->count() > 1) {
            throw AmbiguousAccount::make();
        }
        $account = $matches->first();
        if ($account !== null) {
            app(TenantContext::class)->set($account->tenant_id);
            $account->setConnection(config('database.default'));
        }

        if ($account === null || ! Hash::check($credentials['password'], $account->password_hash)) {
            throw ValidationException::withMessages(['email' => ['Identifiants invalides.']]);
        }

        $token = $account->createToken('silaris-portal', ['portal'], now()->addMinutes(720));
        $account->update(['last_login_at' => now()]);

        return response()->json([
            'token' => $token->plainTextToken,
            'account' => ['id' => $account->id, 'name' => $account->name, 'email' => $account->email],
        ]);
    }

    /** GET /portal/auth/me */
    public function me(Request $request): JsonResponse
    {
        $account = $request->user()->load('party:id,code,name');

        return response()->json([
            'id' => $account->id,
            'name' => $account->name,
            'email' => $account->email,
            'party' => $account->party,
            'notification_prefs' => $account->notification_prefs,
        ]);
    }

    /** POST /portal/auth/logout */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['logged_out' => true]);
    }
}
