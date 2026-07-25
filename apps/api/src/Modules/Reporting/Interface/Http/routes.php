<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Reporting\Interface\Http\Controller\DashboardController;

Route::get('/dashboard', [DashboardController::class, 'show'])->can('dashboard.read');
