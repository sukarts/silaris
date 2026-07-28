<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Ocean\Interface\Http\Controller\BillOfLadingController;
use Silaris\Modules\Ocean\Interface\Http\Controller\BookingController;
use Silaris\Modules\Ocean\Interface\Http\Controller\ContainerController;
use Silaris\Modules\Ocean\Interface\Http\Controller\DemurrageController;
use Silaris\Modules\Ocean\Interface\Http\Controller\PackageController;

Route::prefix('bookings')->group(function (): void {
    Route::get('/', [BookingController::class, 'index'])->can('bookings.read');
    Route::post('/', [BookingController::class, 'store'])->can('bookings.create');
    Route::patch('/{bookingId}', [BookingController::class, 'update'])->whereUuid('bookingId')->can('bookings.update');
    Route::post('/{bookingId}/confirm', [BookingController::class, 'confirm'])->whereUuid('bookingId')->can('bookings.update');
    Route::post('/{bookingId}/roll', [BookingController::class, 'roll'])->whereUuid('bookingId')->can('bookings.update');
});

Route::prefix('containers')->group(function (): void {
    Route::get('/', [ContainerController::class, 'index'])->can('containers.read');
    Route::post('/', [ContainerController::class, 'store'])->can('containers.create');
    Route::patch('/assignments/{assignmentId}', [ContainerController::class, 'updateAssignment'])->whereUuid('assignmentId')->can('containers.update');
    Route::get('/{containerId}', [ContainerController::class, 'show'])->whereUuid('containerId')->can('containers.read');
    Route::post('/{containerId}/assign', [ContainerController::class, 'assign'])->whereUuid('containerId')->can('containers.update');
});

Route::prefix('bills-of-lading')->group(function (): void {
    Route::get('/', [BillOfLadingController::class, 'index'])->can('bl.read');
    Route::post('/', [BillOfLadingController::class, 'store'])->can('bl.create');
    Route::patch('/{blId}', [BillOfLadingController::class, 'update'])->whereUuid('blId')->can('bl.update');
    Route::post('/{blId}/transition', [BillOfLadingController::class, 'transition'])->whereUuid('blId')->can('bl.issue');
});

Route::prefix('packages')->group(function (): void {
    Route::get('/', [PackageController::class, 'index'])->can('packages.read');
    Route::post('/scan', [PackageController::class, 'scan'])->can('packages.scan');
    Route::post('/request-delivery-otp', [PackageController::class, 'requestDeliveryOtp'])->can('packages.scan');
});

Route::post('/shipments/{shipmentId}/packages', [PackageController::class, 'store'])
    ->whereUuid('shipmentId')->can('packages.create');
Route::get('/shipments/{shipmentId}/packages/labels', [PackageController::class, 'labels'])
    ->whereUuid('shipmentId')->can('packages.read');

// Franchises et surestaries — la question du matin : quelles boîtes sortir
// aujourd'hui pour ne pas payer ?
Route::prefix('demurrage')->group(function (): void {
    Route::get('/', [DemurrageController::class, 'index'])->can('containers.read');
    Route::patch('/free-time', [DemurrageController::class, 'updateFreeTime'])->can('containers.update');
});
