<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Silaris\Modules\Crm\Infrastructure\Persistence\Model\PortalAccountModel;
use Silaris\Modules\Identity\Infrastructure\Persistence\Model\UserModel;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pose le contexte tenant AVANT auth:sanctum, à partir du token porteur.
 *
 * Indispensable avec la RLS fail-closed + FORCE : auth:sanctum charge l'utilisateur
 * depuis `users`/`portal_accounts` (tables RLS) ; sans contexte tenant, la RLS
 * masquerait la ligne et l'authentification échouerait (401) en production.
 * On résout donc le tenant du token via la connexion système (BYPASSRLS) au préalable.
 */
class ResolveTenantFromToken
{
    /** tokenable_type → table (les deux populations authentifiables). */
    private const TOKENABLE_TABLE = [
        UserModel::class => 'users',
        PortalAccountModel::class => 'portal_accounts',
    ];

    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        if ($bearer !== null && ! $this->context->has()) {
            $token = PersonalAccessToken::findToken($bearer); // personal_access_tokens : hors RLS
            $table = $token !== null ? (self::TOKENABLE_TABLE[$token->tokenable_type] ?? null) : null;
            if ($token !== null && $table !== null) {
                $tenantId = DB::connection(config('database.system_connection'))
                    ->table($table)->where('id', $token->tokenable_id)->value('tenant_id');
                if ($tenantId !== null) {
                    $this->context->set($tenantId);
                }
            }
        }

        return $next($request);
    }
}
