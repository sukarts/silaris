<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Air\Interface\Http\Controller\AirWaybillController;

Route::prefix('air-waybills')->group(function (): void {
    Route::get('/', [AirWaybillController::class, 'index'])->can('awb.read');
    Route::post('/', [AirWaybillController::class, 'store'])->can('awb.create');
    Route::post('/{awbId}/issue', [AirWaybillController::class, 'issue'])->whereUuid('awbId')->can('awb.issue');
});
