<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Silaris\Modules\Shared\Application\Bus\CommandBus;
use Silaris\Modules\Shared\Application\Bus\QueryBus;
use Silaris\Modules\Shipment\Application\Command\AdvanceWorkflowStep\AdvanceWorkflowStepCommand;
use Silaris\Modules\Shipment\Application\Command\CloseShipment\CloseShipmentCommand;
use Silaris\Modules\Shipment\Application\Command\CreateShipment\CreateShipmentCommand;
use Silaris\Modules\Shipment\Application\Query\GetShipmentTimeline\GetShipmentTimelineQuery;
use Silaris\Modules\Shipment\Application\Query\ListShipments\ListShipmentsQuery;
use Silaris\Modules\Shipment\Application\Service\StepApprovalService;
use Silaris\Modules\Shipment\Domain\Service\WorkflowDefinitionProvider;
use Silaris\Modules\Shipment\Domain\Service\WorkflowEngine;
use Silaris\Modules\Shipment\Infrastructure\Persistence\Model\ShipmentModel;
use Silaris\Modules\Shipment\Infrastructure\Persistence\Model\TransportSegmentModel;
use Silaris\Modules\Shipment\Interface\Http\Request\AdvanceStepRequest;
use Silaris\Modules\Shipment\Interface\Http\Request\ListShipmentsRequest;
use Silaris\Modules\Shipment\Interface\Http\Request\StoreShipmentRequest;
use Silaris\Modules\Shipment\Interface\Http\Resource\ShipmentListResource;
use Silaris\Modules\Shipment\Interface\Http\Resource\ShipmentResource;
use Silaris\Modules\Shipment\Interface\Http\Resource\TimelineEventResource;

class ShipmentController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
        private readonly WorkflowDefinitionProvider $workflows,
        private readonly WorkflowEngine $workflow,
        private readonly StepApprovalService $approvals,
    ) {}

    /** GET /api/v1/shipments — liste paginée (curseur) sur read model. */
    public function index(ListShipmentsRequest $request): AnonymousResourceCollection
    {
        $paginator = $this->queries->ask(new ListShipmentsQuery(
            filters: $request->validated('filter', []),
            sort: $request->validated('sort', '-eta'),
            perPage: (int) $request->validated('per_page', 25),
            cursor: $request->validated('cursor'),
        ));

        return ShipmentListResource::collection($paginator);
    }

    /** POST /api/v1/shipments — création via CommandBus. */
    public function store(StoreShipmentRequest $request): JsonResponse
    {
        $data = $request->validated();

        $id = $this->commands->dispatch(new CreateShipmentCommand(
            clientId: $data['client_id'],
            branchId: $data['branch_id'],
            companyId: $data['company_id'],
            agentId: $data['agent_id'],
            // Valeurs indicatives : la cotation acceptée fait foi.
            direction: $data['direction'] ?? 'import',
            mode: $data['mode'] ?? 'sea_fcl',
            incotermCode: $data['incoterm_code'] ?? 'CIF',
            originLocode: $data['origin_locode'] ?? '',
            destinationLocode: $data['destination_locode'] ?? '',
            priority: $data['priority'] ?? 'normal',
            supervisorId: $data['supervisor_id'] ?? null,
            etd: $data['etd'] ?? null,
            eta: $data['eta'] ?? null,
            quoteId: $data['quote_id'] ?? null,
            waiverReason: $data['waiver_reason'] ?? null,
            workflowDefinitionId: $data['workflow_definition_id'] ?? null,
            notes: $data['notes'] ?? null,
        ));

        return (new ShipmentResource($this->loadDetail($id)))
            ->response()
            ->setStatusCode(201)
            ->header('Location', "/api/v1/shipments/{$id}");
    }

    /** GET /api/v1/shipments/{shipment} — détail + étapes workflow + transitions autorisées. */
    public function show(string $shipmentId): JsonResponse
    {
        $model = $this->loadDetail($shipmentId);
        $steps = $this->workflows->stepsOf($model->workflow_definition_id);

        return (new ShipmentResource($model))->additional([
            'workflow' => [
                'steps' => array_values(array_map(fn ($step) => [
                    'key' => $step->key,
                    'label' => $step->label,
                    'position' => $step->position,
                ], $steps)),
                'current' => $model->status,
                'allowed_transitions' => $this->workflow->allowedTransitions($model->workflow_definition_id, $model->status),
            ],
            // Les conteneurs affectés et l'état de leur suivi : c'est depuis le
            // dossier que l'exploitant vérifie que la marchandise remonte.
            'containers' => DB::table('container_assignments')
                ->join('containers', 'containers.id', '=', 'container_assignments.container_id')
                ->leftJoin('tracking_subscriptions', function ($join) use ($shipmentId): void {
                    $join->on('tracking_subscriptions.subject_number', '=', 'containers.number')
                        ->where('tracking_subscriptions.subject_type', '=', 'container')
                        ->where('tracking_subscriptions.shipment_id', '=', $shipmentId);
                })
                ->where('container_assignments.shipment_id', $shipmentId)
                ->orderBy('containers.number')
                ->get([
                    'container_assignments.id',
                    'containers.number',
                    'containers.size_type',
                    'container_assignments.seal_number',
                    'container_assignments.gate_in_at',
                    'container_assignments.discharged_at',
                    'container_assignments.demurrage_ends_at',
                    'container_assignments.detention_ends_at',
                    'tracking_subscriptions.status AS tracking_status',
                    'tracking_subscriptions.last_polled_at',
                    'tracking_subscriptions.last_snapshot',
                ]),
            // Franchises négociées du dossier — portées par le document, éditables
            // depuis l'écran pour alimenter la surveillance surestaries/détention.
            'free_time' => $this->freeTimeOf($model),
        ])->response();
    }

    /** GET /api/v1/shipments/{shipment}/timeline */
    public function timeline(string $shipmentId): AnonymousResourceCollection
    {
        ShipmentModel::findOrFail($shipmentId); // 404 + contrôle scope tenant

        return TimelineEventResource::collection(
            $this->queries->ask(new GetShipmentTimelineQuery($shipmentId)),
        );
    }

    /** POST /api/v1/shipments/{shipment}/advance */
    public function advance(AdvanceStepRequest $request, string $shipmentId): ShipmentResource|JsonResponse
    {
        $nextStep = $request->validated('next_step');

        // Le responsable exploitation franchit l'étape ; l'agent la propose.
        if (! $this->approvals->canDecide()) {
            $shipment = ShipmentModel::findOrFail($shipmentId);
            $requestId = $this->approvals->request($shipmentId, (string) $shipment->status, $nextStep);

            return response()->json([
                'status' => 'pending_approval',
                'request_id' => $requestId,
                'message' => 'Passage proposé — en attente de validation du responsable exploitation.',
            ], 202);
        }

        $this->commands->dispatch(new AdvanceWorkflowStepCommand(
            shipmentId: $shipmentId,
            nextStep: $nextStep,
        ));

        return new ShipmentResource($this->loadDetail($shipmentId));
    }

    /** POST /api/v1/shipments/{shipment}/close */
    public function close(string $shipmentId): ShipmentResource
    {
        // TODO Étape 13 : closedBy = utilisateur authentifié.
        $actorId = request()->user()->id ?? ShipmentModel::findOrFail($shipmentId)->agent_id;

        $this->commands->dispatch(new CloseShipmentCommand($shipmentId, $actorId));

        return new ShipmentResource($this->loadDetail($shipmentId));
    }

    /** POST /api/v1/shipments/{shipment}/segments — ajoute un segment à la chaîne multimodale. */
    public function storeSegment(Request $request, string $shipmentId): JsonResponse
    {
        ShipmentModel::findOrFail($shipmentId);
        $data = $request->validate([
            'mode' => ['required', Rule::in(['sea', 'air', 'road'])],
            'origin_locode' => ['required', 'size:5'],
            'destination_locode' => ['required', 'size:5'],
            'etd' => ['nullable', 'date'],
            'eta' => ['nullable', 'date', 'after_or_equal:etd'],
        ]);

        $position = (int) TransportSegmentModel::where('shipment_id', $shipmentId)->max('position') + 1;
        $segment = TransportSegmentModel::create([
            ...$data,
            'origin_locode' => strtoupper($data['origin_locode']),
            'destination_locode' => strtoupper($data['destination_locode']),
            'shipment_id' => $shipmentId,
            'position' => $position,
        ]);

        return response()->json($segment, 201);
    }

    /** PATCH /api/v1/shipments/{shipment}/segments/{segment} — jalons réels (ATD/ATA). */
    public function updateSegment(Request $request, string $shipmentId, string $segmentId): JsonResponse
    {
        $segment = TransportSegmentModel::where('shipment_id', $shipmentId)->findOrFail($segmentId);
        $segment->update($request->validate([
            'etd' => ['nullable', 'date'],
            'eta' => ['nullable', 'date'],
            'atd' => ['nullable', 'date'],
            'ata' => ['nullable', 'date'],
        ]));

        return response()->json($segment->fresh());
    }

    private function loadDetail(string $id): ShipmentModel
    {
        return ShipmentModel::with(['client', 'agent', 'branch', 'cargoItems', 'segments'])->findOrFail($id);
    }

    /**
     * Franchises du dossier — sur le connaissement à l'import, le booking à
     * l'export. Le sens dit sur quel document l'écran doit les enregistrer.
     *
     * @return array{direction: string, document: string, demurrage_free_days: ?int, detention_free_days: ?int}
     */
    private function freeTimeOf(ShipmentModel $shipment): array
    {
        // direction est un enum : comparer sur sa valeur.
        $isExport = $shipment->direction->value === 'export';
        $row = $isExport
            ? DB::table('bookings')->where('shipment_id', $shipment->id)
                ->orderByDesc('created_at')->first(['demurrage_free_days', 'detention_free_days'])
            : DB::table('bills_of_lading')->where('shipment_id', $shipment->id)
                ->where('type', 'master')->orderByDesc('created_at')->first(['demurrage_free_days', 'detention_free_days']);

        return [
            'direction' => $isExport ? 'export' : 'import',
            'document' => $isExport ? 'booking' : 'connaissement maître',
            'demurrage_free_days' => $row?->demurrage_free_days === null ? null : (int) $row->demurrage_free_days,
            'detention_free_days' => $row?->detention_free_days === null ? null : (int) $row->detention_free_days,
        ];
    }
}
