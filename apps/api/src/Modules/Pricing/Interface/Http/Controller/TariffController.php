<?php

declare(strict_types=1);

namespace Silaris\Modules\Pricing\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Silaris\Modules\Pricing\Infrastructure\Persistence\Model\TariffModel;

class TariffController
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['sometimes', Rule::in(['sea_fcl', 'sea_lcl', 'air', 'road', 'any'])],
            'side' => ['sometimes', Rule::in(['buy', 'sell'])],
        ]);

        return response()->json(
            TariffModel::withCount('lines')
                ->when($validated['mode'] ?? null, fn ($q, $m) => $q->where('mode', $m))
                ->when($validated['side'] ?? null, fn ($q, $s) => $q->where('side', $s))
                ->orderByDesc('valid_from')
                ->cursorPaginate(25),
        );
    }

    public function show(string $tariffId): JsonResponse
    {
        return response()->json(TariffModel::with('lines')->findOrFail($tariffId));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mode' => ['required', Rule::in(['sea_fcl', 'sea_lcl', 'air', 'road', 'any'])],
            'side' => ['required', Rule::in(['buy', 'sell'])],
            'origin_locode' => ['nullable', 'size:5'],
            'destination_locode' => ['nullable', 'size:5'],
            'supplier_id' => ['nullable', 'uuid', 'exists:parties,id'],
            'party_id' => ['nullable', 'uuid', 'exists:parties,id'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.service_code' => ['required', 'string', 'max:32'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.unit' => ['required', Rule::in(['container', 'kg', 'm3', 'wm', 'flat', 'percent'])],
            'lines.*.container_size_type' => ['nullable', 'string', 'max:8'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.currency_code' => ['required', 'size:3', 'exists:currencies,code'],
            'lines.*.minimum' => ['nullable', 'numeric', 'min:0'],
            'lines.*.weight_from_kg' => ['nullable', 'numeric', 'min:0'],
            'lines.*.weight_to_kg' => ['nullable', 'numeric', 'gt:lines.*.weight_from_kg'],
        ]);

        $tariff = DB::transaction(function () use ($data) {
            $lines = $data['lines'];
            unset($data['lines']);
            $tariff = TariffModel::create($data);
            foreach ($lines as $line) {
                $tariff->lines()->create($line);
            }

            return $tariff;
        });

        return response()->json($tariff->fresh('lines'), 201);
    }

    public function destroy(string $tariffId): JsonResponse
    {
        TariffModel::findOrFail($tariffId)->delete(); // soft delete

        return response()->json(null, 204);
    }
}
