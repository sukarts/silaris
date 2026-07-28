<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Tracking\Interface\Http\Controller\TrackingRefreshController;
use Silaris\Modules\Tracking\Interface\Http\Controller\TrackingSubscribeController;

// Actualisation manuelle du suivi d'un dossier (hors cadence planifiée).
Route::post('/shipments/{shipmentId}/tracking/refresh', TrackingRefreshController::class)
    ->whereUuid('shipmentId')
    ->can('shipments.update');

// Mise sous suivi depuis un numéro — à l'import, le connaissement est souvent
// la seule prise dont dispose le transitaire.
Route::post('/shipments/{shipmentId}/tracking/subscribe', TrackingSubscribeController::class)
    ->whereUuid('shipmentId')
    ->can('shipments.update');
