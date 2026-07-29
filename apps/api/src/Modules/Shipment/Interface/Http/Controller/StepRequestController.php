<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Silaris\Modules\Shared\Application\Bus\CommandBus;
use Silaris\Modules\Shipment\Application\Command\AdvanceWorkflowStep\AdvanceWorkflowStepCommand;
use Silaris\Modules\Shipment\Application\Service\StepApprovalService;

/**
 * File des passages d'étape proposés par les agents.
 *
 * Valider exécute réellement le franchissement : les conditions du workflow
 * (documents requis, transitions permises) sont donc vérifiées au moment de la
 * décision, pas à celui de la demande — l'agent a pu compléter le dossier
 * entre-temps.
 */
class StepRequestController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly StepApprovalService $approvals,
    ) {}

    /**
     * Service auquel la validation est bornée, ou null quand elle porte sur
     * tous les dossiers. La portée est lue dans les droits, jamais déduite du
     * nom du rôle — un tenant peut renommer ses rôles.
     */
    private static function restrictedToService(): ?string
    {
        $user = Auth::user();
        if ($user === null) {
            return null;
        }

        // Portée globale : rien à restreindre. Sinon, le service du chef.
        return $user->hasPermission('shipments.approve_step_all') ? null : $user->service_id;
    }

    /** GET /v1/shipments/step-requests — ce que le responsable doit trancher. */
    public function index(): JsonResponse
    {
        $rows = DB::table('shipment_step_requests AS r')
            ->join('shipments AS s', 's.id', '=', 'r.shipment_id')
            ->leftJoin('users AS u', 'u.id', '=', 'r.requested_by')
            ->leftJoin('parties AS p', 'p.id', '=', 's.client_id')
            ->where('r.status', 'pending')
            // Le chef de service ne voit que ses dossiers ; le responsable
            // exploitation, tous.
            ->when(self::restrictedToService(), fn ($query, $serviceId) => $query->where('s.service_id', $serviceId))
            ->orderBy('r.requested_at')
            ->limit(100)
            ->get([
                'r.id', 'r.from_step', 'r.to_step', 'r.requested_at',
                's.id AS shipment_id', 's.reference',
                'p.name AS client_name',
                DB::raw("COALESCE(u.first_name || ' ' || u.last_name, '—') AS requested_by"),
            ]);

        return response()->json(['data' => $rows]);
    }

    /** POST /v1/shipments/step-requests/{requestId}/decide */
    public function decide(Request $request, string $requestId): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'note' => ['required_if:decision,rejected', 'nullable', 'string', 'max:500'],
        ]);

        $pending = DB::table('shipment_step_requests AS r')
            ->join('shipments AS s', 's.id', '=', 'r.shipment_id')
            ->where('r.id', $requestId)
            ->first(['r.id', 'r.shipment_id', 'r.to_step', 'r.status', 's.service_id']);

        if ($pending === null) {
            return response()->json(['message' => 'Demande introuvable.'], 404);
        }

        $ownService = self::restrictedToService();
        if ($ownService !== null && $pending->service_id !== $ownService) {
            return response()->json([
                'message' => 'Ce dossier relève d\'un autre service.',
            ], 403);
        }

        if ($pending->status !== 'pending') {
            return response()->json(['message' => 'Cette demande a déjà été tranchée.'], 422);
        }

        if ($data['decision'] === 'approved') {
            // Le franchissement s'exécute ici : si le workflow le refuse encore,
            // l'exception remonte et la demande reste ouverte.
            $this->commands->dispatch(new AdvanceWorkflowStepCommand(
                shipmentId: $pending->shipment_id,
                nextStep: $pending->to_step,
            ));
        }

        $this->approvals->close($requestId, $data['decision'], $data['note'] ?? null);

        return response()->json(['status' => $data['decision'], 'step' => $pending->to_step]);
    }
}
