<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Shipment\Interface\Http\Controller\PortalShipmentController;

Route::get('/shipments', [PortalShipmentController::class, 'index']);
Route::get('/shipments/{shipmentId}', [PortalShipmentController::class, 'show'])->whereUuid('shipmentId');
