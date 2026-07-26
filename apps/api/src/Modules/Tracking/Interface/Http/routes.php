<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Tracking\Interface\Http\Controller\TrackingRefreshController;

// Actualisation manuelle du suivi d'un dossier (hors cadence planifiée).
Route::post('/shipments/{shipmentId}/tracking/refresh', TrackingRefreshController::class)
    ->whereUuid('shipmentId')
    ->can('shipments.update');
