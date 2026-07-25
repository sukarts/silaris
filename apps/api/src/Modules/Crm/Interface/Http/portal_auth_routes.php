<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Crm\Interface\Http\Controller\PortalAuthController;

Route::post('/login', [PortalAuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'portal-user', 'tenant'])->group(function (): void {
    Route::get('/me', [PortalAuthController::class, 'me']);
    Route::post('/logout', [PortalAuthController::class, 'logout']);
});
