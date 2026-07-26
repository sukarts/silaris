<?php

declare(strict_types=1);

namespace Silaris\Modules\Road\Interface\Http\Controller;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Silaris\Modules\Road\Infrastructure\Persistence\Model\DriverModel;
use Silaris\Modules\Road\Infrastructure\Persistence\Model\TrailerModel;
use Silaris\Modules\Road\Infrastructure\Persistence\Model\TruckModel;

class FleetController
{
    /**
     * Un moyen affrété appartient à un fournisseur du CRM ; nul, il relève de
     * la flotte propre.
     *
     * @var list<string>
     */
    private const CARRIER_RULES = ['nullable', 'uuid', 'exists:parties,id'];

    /**
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     */
    private static function scopeToCarrier(Builder $query, Request $request): Builder
    {
        $carrier = $request->query('carrier_party_id');
        if ($carrier === 'own') {
            return $query->whereNull('carrier_party_id');
        }

        return is_string($carrier) && $carrier !== '' ? $query->where('carrier_party_id', $carrier) : $query;
    }

    public function trucks(Request $request): JsonResponse
    {
        return response()->json(
            self::scopeToCarrier(TruckModel::query(), $request)->orderBy('plate_number')->cursorPaginate(50),
        );
    }

    public function storeTruck(Request $request): JsonResponse
    {
        return response()->json(TruckModel::create($request->validate([
            'plate_number' => ['required', 'string', 'max:16'],
            'type' => ['nullable', 'string', 'max:32'],
            'carrier_party_id' => self::CARRIER_RULES,
            'capacity_kg' => ['nullable', 'numeric', 'min:0'],
            'inspection_due' => ['nullable', 'date'],
            'insurance_due' => ['nullable', 'date'],
        ])), 201);
    }

    public function trailers(Request $request): JsonResponse
    {
        return response()->json(
            self::scopeToCarrier(TrailerModel::query(), $request)->orderBy('plate_number')->cursorPaginate(50),
        );
    }

    public function storeTrailer(Request $request): JsonResponse
    {
        return response()->json(TrailerModel::create($request->validate([
            'plate_number' => ['required', 'string', 'max:16'],
            'type' => ['nullable', 'string', 'max:32'],
            'carrier_party_id' => self::CARRIER_RULES,
        ])), 201);
    }

    public function drivers(Request $request): JsonResponse
    {
        return response()->json(
            self::scopeToCarrier(DriverModel::query(), $request)->orderBy('name')->cursorPaginate(50),
        );
    }

    public function storeDriver(Request $request): JsonResponse
    {
        return response()->json(DriverModel::create($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'carrier_party_id' => self::CARRIER_RULES,
            'phone' => ['nullable', 'string', 'max:32'],
            'license_number' => ['nullable', 'string', 'max:32'],
            'license_categories' => ['nullable', 'string', 'max:32'],
            'license_expiry' => ['nullable', 'date'],
        ])), 201);
    }
}
