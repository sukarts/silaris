<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Reporting\Interface\Http\Controller\DashboardController;
use Silaris\Modules\Reporting\Interface\Http\Controller\ReportController;

Route::get('/dashboard', [DashboardController::class, 'show'])->can('dashboard.read');

// Rapports de gestion : marge (offres gagnées) et chiffre d'affaires (facturé).
Route::get('/reports/business', [ReportController::class, 'business'])->can('reports.read');
