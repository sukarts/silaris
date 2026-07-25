<?php

declare(strict_types=1);

namespace Silaris\Modules\Road\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Silaris\Modules\Road\Infrastructure\Persistence\Model\DriverModel;
use Silaris\Modules\Road\Infrastructure\Persistence\Model\TrailerModel;
use Silaris\Modules\Road\Infrastructure\Persistence\Model\TruckModel;

class FleetController
{
    public function trucks(): JsonResponse
    {
        return response()->json(TruckModel::orderBy('plate_number')->cursorPaginate(50));
    }

    public function storeTruck(Request $request): JsonResponse
    {
        return response()->json(TruckModel::create($request->validate([
            'plate_number' => ['required', 'string', 'max:16'],
            'type' => ['nullable', 'string', 'max:32'],
            'capacity_kg' => ['nullable', 'numeric', 'min:0'],
            'inspection_due' => ['nullable', 'date'],
            'insurance_due' => ['nullable', 'date'],
        ])), 201);
    }

    public function trailers(): JsonResponse
    {
        return response()->json(TrailerModel::orderBy('plate_number')->cursorPaginate(50));
    }

    public function storeTrailer(Request $request): JsonResponse
    {
        return response()->json(TrailerModel::create($request->validate([
            'plate_number' => ['required', 'string', 'max:16'],
            'type' => ['nullable', 'string', 'max:32'],
        ])), 201);
    }

    public function drivers(): JsonResponse
    {
        return response()->json(DriverModel::orderBy('name')->cursorPaginate(50));
    }

    public function storeDriver(Request $request): JsonResponse
    {
        return response()->json(DriverModel::create($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'phone' => ['nullable', 'string', 'max:32'],
            'license_number' => ['nullable', 'string', 'max:32'],
            'license_categories' => ['nullable', 'string', 'max:32'],
            'license_expiry' => ['nullable', 'date'],
        ])), 201);
    }
}
