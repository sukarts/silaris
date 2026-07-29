<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Shipment\Interface\Http\Controller\QuoteWaiverController;
use Silaris\Modules\Shipment\Interface\Http\Controller\ShipmentController;
use Silaris\Modules\Shipment\Interface\Http\Controller\StepRequestController;

Route::prefix('shipments')->group(function (): void {
    Route::get('/', [ShipmentController::class, 'index'])->can('shipments.read');
    Route::post('/', [ShipmentController::class, 'store'])->can('shipments.create');
    Route::get('/{shipmentId}', [ShipmentController::class, 'show'])->whereUuid('shipmentId')->can('shipments.read');
    Route::get('/{shipmentId}/timeline', [ShipmentController::class, 'timeline'])->whereUuid('shipmentId')->can('shipments.read');
    // File d'attente et décision de la direction sur les ouvertures dérogatoires.
    Route::get('/waivers', [QuoteWaiverController::class, 'index'])->can('derogations.open_shipment_without_quote');
    Route::post('/{shipmentId}/waiver/decide', [QuoteWaiverController::class, 'decide'])
        ->whereUuid('shipmentId')->can('derogations.open_shipment_without_quote');

    // File des passages proposés par les agents, et décision du responsable.
    Route::get('/step-requests', [StepRequestController::class, 'index'])->can('shipments.approve_step');
    Route::post('/step-requests/{requestId}/decide', [StepRequestController::class, 'decide'])
        ->whereUuid('requestId')->can('shipments.approve_step');

    Route::post('/{shipmentId}/advance', [ShipmentController::class, 'advance'])->whereUuid('shipmentId')->can('shipments.advance');
    Route::post('/{shipmentId}/close', [ShipmentController::class, 'close'])->whereUuid('shipmentId')->can('shipments.close');
    Route::post('/{shipmentId}/segments', [ShipmentController::class, 'storeSegment'])->whereUuid('shipmentId')->can('shipments.update');
    Route::patch('/{shipmentId}/segments/{segmentId}', [ShipmentController::class, 'updateSegment'])->whereUuid('shipmentId')->can('shipments.update');
});
