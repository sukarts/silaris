<?php

declare(strict_types=1);

namespace Silaris\Modules\Road\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Silaris\Modules\Road\Infrastructure\Persistence\Model\MissionModel;
use Silaris\Modules\Shared\Domain\Exception\DomainException;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;

final class MissionTransitionForbidden extends DomainException
{
    public static function make(string $from, string $to): self
    {
        return new self("Transition mission interdite : {$from} → {$to}");
    }

    public function errorCode(): string
    {
        return 'mission.invalid_transition';
    }
}

class MissionController
{
    private const TRANSITIONS = [
        'planned' => ['in_progress', 'cancelled'],
        'in_progress' => ['delivered', 'failed'],
        'delivered' => [],
        'failed' => ['planned'],
        'cancelled' => [],
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(array_keys(self::TRANSITIONS))],
            'driver_id' => ['sometimes', 'uuid'],
            'date' => ['sometimes', 'date'],
        ]);

        return response()->json(
            MissionModel::with(['driver:id,name', 'truck:id,plate_number', 'carrier:id,name', 'shipment:id,reference', 'stops'])
                ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->when($validated['driver_id'] ?? null, fn ($q, $d) => $q->where('driver_id', $d))
                ->when($validated['date'] ?? null, fn ($q, $d) => $q->whereDate('window_start', $d))
                ->orderBy('window_start')
                ->cursorPaginate(25),
        );
    }

    public function store(Request $request, TenantContext $tenant): JsonResponse
    {
        $data = $request->validate([
            'shipment_id' => ['nullable', 'uuid', 'exists:shipments,id'],
            'carrier_party_id' => ['nullable', 'uuid', 'exists:parties,id'],
            'carrier_reference' => ['nullable', 'string', 'max:64'],
            'truck_id' => ['nullable', 'uuid', 'exists:trucks,id'],
            'trailer_id' => ['nullable', 'uuid', 'exists:trailers,id'],
            'driver_id' => ['nullable', 'uuid', 'exists:drivers,id'],
            'type' => ['sometimes', Rule::in(['delivery', 'pickup', 'transfer'])],
            'window_start' => ['nullable', 'date'],
            'window_end' => ['nullable', 'date', 'after_or_equal:window_start'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'stops' => ['sometimes', 'array', 'max:20'],
            'stops.*.label' => ['required_with:stops', 'string', 'max:255'],
            'stops.*.address' => ['sometimes', 'array'],
            'stops.*.planned_at' => ['nullable', 'date'],
        ]);

        $stops = $data['stops'] ?? [];
        unset($data['stops']);

        $this->assertResourcesBelongToCarrier($data);

        $sequence = DB::selectOne('SELECT next_sequence(?, ?) AS value', [$tenant->id(), 'mission:'.date('Y')])->value;
        $mission = MissionModel::create([...$data, 'reference' => sprintf('MIS-%d-%03d', date('Y'), $sequence)]);
        foreach ($stops as $i => $stop) {
            $mission->stops()->create([...$stop, 'position' => $i + 1]);
        }

        return response()->json($mission->fresh(['stops']), 201);
    }

    /**
     * Un camion ou un chauffeur appartenant à un prestataire ne peut être
     * affecté qu'aux missions confiées à ce prestataire : sans ce garde-fou,
     * le dossier attribuerait le moyen d'un transporteur à un autre.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertResourcesBelongToCarrier(array $data): void
    {
        $missionCarrier = $data['carrier_party_id'] ?? null;

        foreach (['truck_id' => 'trucks', 'trailer_id' => 'trailers', 'driver_id' => 'drivers'] as $key => $table) {
            $id = $data[$key] ?? null;
            if ($id === null) {
                continue;
            }

            $owner = DB::table($table)->where('id', $id)->value('carrier_party_id');
            if ($owner === $missionCarrier) {
                continue;
            }

            throw ValidationException::withMessages([$key => match (true) {
                $owner === null => 'Ce moyen appartient à la flotte propre : retirez le transporteur affrété.',
                $missionCarrier === null => 'Ce moyen appartient à un prestataire : renseignez le transporteur de la mission.',
                default => 'Ce moyen appartient à un autre transporteur que celui de la mission.',
            }]);
        }
    }

    /** POST /v1/missions/{id}/transition — start / deliver / fail / cancel. */
    public function transition(Request $request, string $missionId): JsonResponse
    {
        $mission = MissionModel::findOrFail($missionId);
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(self::TRANSITIONS))],
            'failure_reason' => ['required_if:status,failed', 'nullable', 'string', 'max:255'],
        ]);

        if (! in_array($data['status'], self::TRANSITIONS[$mission->status], true)) {
            throw MissionTransitionForbidden::make($mission->status, $data['status']);
        }

        $mission->update([
            'status' => $data['status'],
            'failure_reason' => $data['failure_reason'] ?? null,
            ...($data['status'] === 'in_progress' ? ['started_at' => now()] : []),
            ...(in_array($data['status'], ['delivered', 'failed'], true) ? ['completed_at' => now()] : []),
        ]);

        return response()->json($mission->fresh());
    }

    /** POST /v1/missions/{id}/pod — preuve de livraison (signature + photos + géoloc). */
    public function submitPod(Request $request, string $missionId): JsonResponse
    {
        $mission = MissionModel::where('status', 'in_progress')->findOrFail($missionId);
        $data = $request->validate([
            'recipient_name' => ['required', 'string', 'max:255'],
            'signature_data' => ['nullable', 'string', 'max:500000'],
            'photo_document_ids' => ['sometimes', 'array', 'max:10'],
            'photo_document_ids.*' => ['uuid'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $pod = DB::transaction(function () use ($mission, $data) {
            $pod = $mission->pod()->create([...$data, 'delivered_at' => now(), 'tenant_id' => $mission->tenant_id]);
            $mission->update(['status' => 'delivered', 'completed_at' => now()]);

            return $pod;
        });

        return response()->json($pod, 201);
    }
}
