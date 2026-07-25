<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Silaris\Modules\Crm\Infrastructure\Persistence\Model\PortalAccountModel;
use Symfony\Component\HttpFoundation\Response;

/** Les routes portail n'acceptent jamais un token interne. */
class EnsurePortalUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof PortalAccountModel) {
            return problem(403, 'Accès réservé au portail client', 'https://silaris.app/errors/forbidden');
        }

        return $next($request);
    }
}
