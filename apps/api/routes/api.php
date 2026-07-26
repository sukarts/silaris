<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Silaris\Modules\Road\Interface\Http\Controller\TelematicsController;

/*
|--------------------------------------------------------------------------
| API SILARIS — /api/v1
|--------------------------------------------------------------------------
| Espaces :
|   /v1/auth/*        authentification interne (throttle:login)
|   /v1/portal/auth/* authentification portail client
|   /v1/public/*      sans auth, throttle strict
|   /v1/portal/*      portail client (token portail + tenant)
|   /v1/*             interne (token utilisateur + tenant)
*/

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->json(['status' => 'ok', 'time' => now()->toIso8601String()]));
    Route::get('/ready', function () {
        $checks = ['database' => false];
        try {
            DB::select('SELECT 1');
            $checks['database'] = true;
        } catch (Throwable) {
        }
        $ready = ! in_array(false, $checks, true);

        return response()->json(['ready' => $ready, 'checks' => $checks], $ready ? 200 : 503);
    });

    // Authentification interne
    Route::prefix('auth')->middleware('throttle:login')->group(
        base_path('src/Modules/Identity/Interface/Http/auth_routes.php'),
    );

    // Authentification portail
    Route::prefix('portal/auth')->middleware('throttle:login')->group(
        base_path('src/Modules/Crm/Interface/Http/portal_auth_routes.php'),
    );

    // Ingestion télématique — authentifiée par clé de balise (pas de session).
    Route::middleware('throttle:telematics')->post(
        '/telematics/positions',
        [TelematicsController::class, 'store'],
    );

    // Routes publiques — throttle strict
    Route::middleware('throttle:public-tracking')->prefix('public')->group(function (): void {
        foreach (glob(base_path('src/Modules/*/Interface/Http/public_routes.php')) as $publicRoutes) {
            require $publicRoutes;
        }
    });

    // Routes portail client (données) — Étape 18
    Route::prefix('portal')->middleware(['auth:sanctum', 'portal-user', 'tenant'])->group(function (): void {
        foreach (glob(base_path('src/Modules/*/Interface/Http/portal_routes.php')) as $portalRoutes) {
            require $portalRoutes;
        }
    });

    // Routes internes — token utilisateur + tenant
    Route::middleware(['auth:sanctum', 'internal', 'tenant', 'throttle:api'])->group(function (): void {
        foreach (glob(base_path('src/Modules/*/Interface/Http/routes.php')) as $moduleRoutes) {
            require $moduleRoutes;
        }
    });
});
