<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Résout le contexte tenant de la requête :
 *  1. utilisateur authentifié → son tenant_id (source de vérité, Étape 13) ;
 *  2. environnement local/testing uniquement : en-tête X-Tenant-Slug (outillage dev).
 * Sans tenant résolu → 400. Positionne aussi app.tenant_id (RLS PostgreSQL).
 */
class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->user()?->tenant_id;

        if ($tenantId === null && app()->environment(['local', 'testing'])) {
            $slug = $request->header('X-Tenant-Slug');
            if ($slug !== null) {
                $tenantId = DB::table('tenants')->where('slug', $slug)->where('is_active', true)->value('id');
            }
        }

        if ($tenantId === null) {
            return problem(400, 'Tenant non résolu', 'https://silaris.app/errors/tenant-unresolved');
        }

        $this->context->set($tenantId);

        return $next($request);
    }
}
