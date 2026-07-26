<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Road\Interface\Http\Controller\PortalDeliveryNoteController;

Route::get('/shipments/{shipmentId}/delivery-notes', [PortalDeliveryNoteController::class, 'index'])->whereUuid('shipmentId');
Route::get('/missions/{missionId}/delivery-note', [PortalDeliveryNoteController::class, 'pdf'])->whereUuid('missionId');
