<?php

declare(strict_types=1);

namespace Silaris\Modules\Ocean\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Silaris\Modules\Ocean\Infrastructure\Persistence\Model\BillOfLadingModel;
use Silaris\Modules\Shared\Domain\Exception\DomainException;

final class BlTransitionForbidden extends DomainException
{
    public static function make(string $from, string $to): self
    {
        return new self("Transition BL interdite : {$from} → {$to}");
    }

    public function errorCode(): string
    {
        return 'bl.invalid_transition';
    }
}

class BillOfLadingController
{
    /** draft → verified → issued → surrendered — jamais de retour arrière. */
    private const TRANSITIONS = [
        'draft' => ['verified'],
        'verified' => ['issued', 'draft'],
        'issued' => ['surrendered'],
        'surrendered' => [],
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shipment_id' => ['sometimes', 'uuid'],
            'type' => ['sometimes', Rule::in(['master', 'house'])],
            'status' => ['sometimes', Rule::in(array_keys(self::TRANSITIONS))],
        ]);

        return response()->json(
            BillOfLadingModel::with(['shipment:id,reference', 'houses:id,parent_id,number,status'])
                ->when($validated['shipment_id'] ?? null, fn ($q, $s) => $q->where('shipment_id', $s))
                ->when($validated['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
                ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->orderByDesc('created_at')
                ->cursorPaginate(25),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'shipment_id' => ['required', 'uuid', 'exists:shipments,id'],
            'type' => ['required', Rule::in(['master', 'house'])],
            'parent_id' => ['nullable', 'uuid', Rule::exists('bills_of_lading', 'id')->where('type', 'master')],
            'number' => ['required', 'string', 'max:32'],
            'release_type' => ['sometimes', Rule::in(['original', 'telex', 'seaway'])],
            'shipper' => ['required', 'array'],
            'consignee' => ['required', 'array'],
            'notify_party' => ['sometimes', 'array'],
            'goods_description' => ['nullable', 'string', 'max:5000'],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'volume_m3' => ['nullable', 'numeric', 'min:0'],
            'packages_count' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json(BillOfLadingModel::create($data), 201);
    }

    /** PATCH — uniquement en draft (les BL émis sont immuables : snapshots légaux). */
    public function update(Request $request, string $blId): JsonResponse
    {
        $bl = BillOfLadingModel::where('status', 'draft')->findOrFail($blId);
        $bl->update($request->validate([
            'number' => ['sometimes', 'string', 'max:32'],
            'release_type' => ['sometimes', Rule::in(['original', 'telex', 'seaway'])],
            'shipper' => ['sometimes', 'array'],
            'consignee' => ['sometimes', 'array'],
            'notify_party' => ['sometimes', 'array'],
            'goods_description' => ['nullable', 'string', 'max:5000'],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'volume_m3' => ['nullable', 'numeric', 'min:0'],
            'packages_count' => ['nullable', 'integer', 'min:0'],
        ]));

        return response()->json($bl->fresh());
    }

    /** POST /v1/bills-of-lading/{id}/transition */
    public function transition(Request $request, string $blId): JsonResponse
    {
        $bl = BillOfLadingModel::findOrFail($blId);
        $target = $request->validate(['status' => ['required', Rule::in(array_keys(self::TRANSITIONS))]])['status'];

        if (! in_array($target, self::TRANSITIONS[$bl->status], true)) {
            throw BlTransitionForbidden::make($bl->status, $target);
        }

        $bl->update([
            'status' => $target,
            ...($target === 'issued' ? ['issued_at' => now(), 'issued_by' => $request->user()?->id] : []),
        ]);

        return response()->json($bl->fresh());
    }
}
