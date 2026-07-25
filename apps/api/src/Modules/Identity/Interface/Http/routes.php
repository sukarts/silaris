<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Identity\Interface\Http\Controller\RoleController;
use Silaris\Modules\Identity\Interface\Http\Controller\UserController;

Route::prefix('admin')->group(function (): void {
    Route::get('/users', [UserController::class, 'index'])->can('users.read');
    Route::post('/users', [UserController::class, 'store'])->can('users.create');
    Route::patch('/users/{userId}', [UserController::class, 'update'])->whereUuid('userId')->can('users.update');
    Route::post('/users/{userId}/reset-mfa', [UserController::class, 'resetMfa'])->whereUuid('userId')->can('users.reset_mfa');

    Route::get('/roles', [RoleController::class, 'index'])->can('roles.read');
    Route::post('/roles', [RoleController::class, 'store'])->can('roles.create');
    Route::patch('/roles/{roleId}', [RoleController::class, 'update'])->whereUuid('roleId')->can('roles.update');
    Route::get('/permissions', [RoleController::class, 'permissions'])->can('roles.read');
});
