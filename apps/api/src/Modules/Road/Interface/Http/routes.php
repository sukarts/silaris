<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Road\Interface\Http\Controller\FleetController;
use Silaris\Modules\Road\Interface\Http\Controller\MissionController;
use Silaris\Modules\Road\Interface\Http\Controller\TelematicsController;

Route::prefix('fleet')->group(function (): void {
    Route::get('/trucks', [FleetController::class, 'trucks'])->can('road.read');
    Route::post('/trucks', [FleetController::class, 'storeTruck'])->can('road.create');
    Route::get('/trailers', [FleetController::class, 'trailers'])->can('road.read');
    Route::post('/trailers', [FleetController::class, 'storeTrailer'])->can('road.create');
    Route::get('/drivers', [FleetController::class, 'drivers'])->can('road.read');
    Route::post('/drivers', [FleetController::class, 'storeDriver'])->can('road.create');
});

Route::prefix('missions')->group(function (): void {
    Route::get('/', [MissionController::class, 'index'])->can('road.read');
    Route::post('/', [MissionController::class, 'store'])->can('road.create');
    Route::post('/{missionId}/transition', [MissionController::class, 'transition'])->whereUuid('missionId')->can('road.update');
    Route::post('/{missionId}/pod', [MissionController::class, 'submitPod'])->whereUuid('missionId')->can('pod.create');
    Route::get('/{missionId}/positions', [TelematicsController::class, 'missionTrack'])->whereUuid('missionId')->can('road.read');
});

// Balises véhicule (télématique) — parc et enrôlement.
Route::prefix('road/devices')->group(function (): void {
    Route::get('/', [TelematicsController::class, 'devices'])->can('road.read');
    Route::post('/', [TelematicsController::class, 'storeDevice'])->can('road.create');
    Route::patch('/{deviceId}', [TelematicsController::class, 'updateDevice'])->whereUuid('deviceId')->can('road.update');
});
