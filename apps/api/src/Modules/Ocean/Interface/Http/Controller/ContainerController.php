<?php

declare(strict_types=1);

namespace Silaris\Modules\Ocean\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Silaris\Modules\Ocean\Application\Service\FreeTimeTracker;
use Silaris\Modules\Ocean\Infrastructure\Persistence\Model\ContainerAssignmentModel;
use Silaris\Modules\Ocean\Infrastructure\Persistence\Model\ContainerModel;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Silaris\Modules\Tracking\Application\Service\TrackingSubscriber;

class ContainerController
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly TrackingSubscriber $subscriber,
        private readonly FreeTimeTracker $freeTime,
    ) {}

    private const SIZE_TYPES = ['20GP', '40GP', '40HC', '45HC', '20RF', '40RF', '20OT', '40OT', '20FR', '40FR', '20TK'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['search' => ['sometimes', 'string', 'max:20']]);

        return response()->json(
            ContainerModel::query()
                ->when($validated['search'] ?? null, fn ($q, $s) => $q->whereLike('number', '%'.strtoupper($s).'%'))
                ->withCount(['assignments as active_assignment' => fn ($q) => $q->whereNull('returned_at')])
                ->orderByDesc('created_at')
                ->cursorPaginate(25),
        );
    }

    public function show(string $containerId): JsonResponse
    {
        return response()->json(
            ContainerModel::with(['assignments' => fn ($q) => $q->with('shipment:id,reference')->orderByDesc('created_at')])
                ->findOrFail($containerId),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'size:11', 'regex:/^[A-Z]{4}[0-9]{7}$/'],
            'size_type' => ['required', Rule::in(self::SIZE_TYPES)],
            'tare_kg' => ['nullable', 'numeric', 'min:0'],
            'max_payload_kg' => ['nullable', 'numeric', 'min:0'],
        ]);
        // Le CHECK iso6346_check() PostgreSQL rejettera tout check digit invalide (23514).

        return response()->json(ContainerModel::create($data), 201);
    }

    /** POST /v1/containers/{id}/assign — affectation à un dossier. */
    public function assign(Request $request, string $containerId): JsonResponse
    {
        $container = ContainerModel::findOrFail($containerId);
        $data = $request->validate([
            'shipment_id' => ['required', 'uuid', 'exists:shipments,id'],
            'booking_id' => ['nullable', 'uuid', 'exists:bookings,id'],
            'seal_number' => ['nullable', 'string', 'max:32'],
            'vgm_kg' => ['nullable', 'numeric', 'min:0'],
        ]);

        // La franchise ne se saisit plus sur l'affectation : elle se négocie sur
        // le document (connaissement, booking) et l'échéance en est déduite.
        $assignment = ContainerAssignmentModel::create([...$data, 'container_id' => $containerId]);
        $this->freeTime->refreshDeadlines((string) $assignment->id);

        // Le conteneur porte enfin un numéro rattaché à un dossier : c'est le
        // moment où le suivi transporteur a quelque chose à interroger.
        $this->subscriber->subscribe(
            $this->tenant->id(),
            $data['shipment_id'],
            'container',
            (string) $container->number,
        );

        return response()->json($assignment, 201);
    }

    /** PATCH /v1/containers/assignments/{id} — jalons (gate-in, chargement, restitution…). */
    public function updateAssignment(Request $request, string $assignmentId): JsonResponse
    {
        $assignment = ContainerAssignmentModel::findOrFail($assignmentId);
        $assignment->update($request->validate([
            'seal_number' => ['nullable', 'string', 'max:32'],
            'vgm_kg' => ['nullable', 'numeric', 'min:0'],
            'vgm_verified_at' => ['nullable', 'date'],
            'gate_in_at' => ['nullable', 'date'],
            'loaded_at' => ['nullable', 'date'],
            'discharged_at' => ['nullable', 'date'],
            'gate_out_at' => ['nullable', 'date'],
            'returned_at' => ['nullable', 'date'],
        ]));

        // Un jalon saisi à la main déplace les compteurs autant qu'un jalon reçu
        // du suivi : on recalcule surestaries et détention.
        $this->freeTime->refreshDeadlines((string) $assignment->id);

        return response()->json($assignment->fresh());
    }
}
