<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Identity\Interface\Http\Controller\AuthController;
use Silaris\Modules\Identity\Interface\Http\Controller\MfaController;
use Silaris\Modules\Identity\Interface\Http\Controller\PasswordResetController;

// Sans authentification — throttle:login
Route::post('/login', [AuthController::class, 'login']);
Route::post('/mfa/verify', [AuthController::class, 'verifyMfa']);
Route::post('/forgot-password', [PasswordResetController::class, 'forgot']);
Route::post('/reset-password', [PasswordResetController::class, 'reset']);

// Authentifié interne
Route::middleware(['auth:sanctum', 'internal'])->group(function (): void {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    Route::post('/mfa/enable', [MfaController::class, 'enable']);
    Route::post('/mfa/confirm', [MfaController::class, 'confirm']);
    Route::post('/mfa/disable', [MfaController::class, 'disable']);
});
