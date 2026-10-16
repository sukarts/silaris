<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureInternalUser;
use App\Http\Middleware\EnsurePortalUser;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\ResolveTenantFromToken;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use Silaris\Modules\Shared\Domain\Exception\DomainException;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

if (! function_exists('problem')) {
    /**
     * Réponse d'erreur RFC 9457 (Problem Details for HTTP APIs).
     */
    function problem(int $status, string $title, string $type = 'about:blank', ?string $detail = null, array $extra = []): JsonResponse
    {
        return response()->json(array_filter([
            'type' => $type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            ...$extra,
        ], fn ($v) => $v !== null), $status, ['Content-Type' => 'application/problem+json']);
    }
}

if (! function_exists('tenantTable')) {
    /**
     * Query builder borné au tenant courant — pour les lectures inter-modules
     * qui passent par DB::table et non par un modèle (BelongsToTenant).
     * En production l'application se connecte en propriétaire des tables :
     * la RLS ne la contraint pas, ce filtre est la seule barrière.
     *
     * Accepte un alias : tenantTable('shipments AS s') filtre sur s.tenant_id.
     */
    function tenantTable(string $table): Builder
    {
        $parts = preg_split('/\s+as\s+/i', trim($table));
        $alias = $parts === false ? $table : end($parts);

        return DB::table($table)->where("{$alias}.tenant_id", app(TenantContext::class)->id());
    }
}

if (! function_exists('tenantExists')) {
    /**
     * Règle `exists` bornée au tenant courant : un identifiant d'un autre
     * tenant est traité comme inexistant, jamais comme une 403 révélatrice.
     */
    function tenantExists(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)->where('tenant_id', app(TenantContext::class)->id());
    }
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            ForceJsonResponse::class,
            ResolveTenantFromToken::class,
        ]);
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'tenant-from-token' => ResolveTenantFromToken::class,
            'internal' => EnsureInternalUser::class,
            'portal-user' => EnsurePortalUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(function (DomainException $e, Request $request) {
            if ($request->is('api/*')) {
                return problem(422, 'Règle métier violée', 'https://silaris.app/errors/'.$e->errorCode(), $e->getMessage(), [
                    'error_code' => $e->errorCode(),
                ]);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return problem(422, 'Données invalides', 'https://silaris.app/errors/validation', null, [
                    'errors' => $e->errors(),
                ]);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return problem(401, 'Non authentifié', 'https://silaris.app/errors/unauthenticated');
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return problem(403, 'Accès refusé', 'https://silaris.app/errors/forbidden');
            }
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return problem(404, 'Ressource introuvable', 'https://silaris.app/errors/not-found');
            }
        });

        $exceptions->render(function (HttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return problem($e->getStatusCode(), $e->getMessage() ?: 'Erreur', 'about:blank');
            }
        });
    })->create();
