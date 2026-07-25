<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Silaris\Modules\Identity\Infrastructure\Persistence\Model\UserModel;
use Silaris\Modules\Shared\Infrastructure\Auth\CurrentUser;
use Symfony\Component\HttpFoundation\Response;

/** Routes internes : jamais de token portail. Peuple le contexte CurrentUser (RBAC + scope agences). */
class EnsureInternalUser
{
    public function __construct(private readonly CurrentUser $currentUser) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof UserModel) {
            return problem(403, 'Accès réservé aux utilisateurs internes', 'https://silaris.app/errors/forbidden');
        }

        $this->currentUser->set(
            id: $user->id,
            permissions: $user->permissionKeys(),
            branchIds: $user->branches()->pluck('branches.id')->all(),
            allBranches: $user->hasAllBranchAccess(),
        );

        return $next($request);
    }
}
