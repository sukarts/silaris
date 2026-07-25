<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Shipment\Interface\Http\Controller\ShipmentController;

Route::prefix('shipments')->group(function (): void {
    Route::get('/', [ShipmentController::class, 'index'])->can('shipments.read');
    Route::post('/', [ShipmentController::class, 'store'])->can('shipments.create');
    Route::get('/{shipmentId}', [ShipmentController::class, 'show'])->whereUuid('shipmentId')->can('shipments.read');
    Route::get('/{shipmentId}/timeline', [ShipmentController::class, 'timeline'])->whereUuid('shipmentId')->can('shipments.read');
    Route::post('/{shipmentId}/advance', [ShipmentController::class, 'advance'])->whereUuid('shipmentId')->can('shipments.advance');
    Route::post('/{shipmentId}/close', [ShipmentController::class, 'close'])->whereUuid('shipmentId')->can('shipments.close');
    Route::post('/{shipmentId}/segments', [ShipmentController::class, 'storeSegment'])->whereUuid('shipmentId')->can('shipments.update');
    Route::patch('/{shipmentId}/segments/{segmentId}', [ShipmentController::class, 'updateSegment'])->whereUuid('shipmentId')->can('shipments.update');
});
