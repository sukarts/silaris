<?php

declare(strict_types=1);

namespace Silaris\Modules\Air\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Silaris\Modules\Air\Infrastructure\Persistence\Model\AirWaybillModel;

class AirWaybillController
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shipment_id' => ['sometimes', 'uuid'],
            'type' => ['sometimes', Rule::in(['master', 'house'])],
        ]);

        return response()->json(
            AirWaybillModel::with(['legs', 'shipment:id,reference'])
                ->when($validated['shipment_id'] ?? null, fn ($q, $s) => $q->where('shipment_id', $s))
                ->when($validated['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
                ->orderByDesc('created_at')
                ->cursorPaginate(25),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'shipment_id' => ['required', 'uuid', 'exists:shipments,id'],
            'type' => ['required', Rule::in(['master', 'house'])],
            'parent_id' => ['nullable', 'uuid', Rule::exists('air_waybills', 'id')->where('type', 'master')],
            'number' => ['required', 'string', 'max:16'],
            'airline_id' => ['nullable', 'uuid', 'exists:airlines,id'],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'volume_m3' => ['nullable', 'numeric', 'min:0'],
            'packages_count' => ['nullable', 'integer', 'min:0'],
            'shipper' => ['sometimes', 'array'],
            'consignee' => ['sometimes', 'array'],
            'goods_description' => ['nullable', 'string', 'max:5000'],
            'legs' => ['sometimes', 'array', 'max:6'],
            'legs.*.flight_number' => ['required_with:legs', 'string', 'max:8'],
            'legs.*.origin_iata' => ['required_with:legs', 'size:3', 'exists:airports,iata'],
            'legs.*.destination_iata' => ['required_with:legs', 'size:3', 'exists:airports,iata'],
            'legs.*.departure_at' => ['nullable', 'date'],
            'legs.*.arrival_at' => ['nullable', 'date'],
        ]);
        // Le CHECK awb_mod7() PostgreSQL rejette tout MAWB au chiffre de contrôle invalide.

        $legs = $data['legs'] ?? [];
        unset($data['legs']);

        $awb = AirWaybillModel::create([...$data, 'number' => str_replace(['-', ' '], '', $data['number'])]);
        foreach ($legs as $i => $leg) {
            $awb->legs()->create([...$leg, 'position' => $i + 1]);
        }

        return response()->json($awb->fresh('legs'), 201);
    }

    /** POST /v1/air-waybills/{id}/issue */
    public function issue(Request $request, string $awbId): JsonResponse
    {
        $awb = AirWaybillModel::where('status', 'draft')->findOrFail($awbId);
        $awb->update(['status' => 'issued', 'issued_at' => now(), 'issued_by' => $request->user()?->id]);

        return response()->json($awb->fresh('legs'));
    }
}
