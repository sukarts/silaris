<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Silaris\Modules\Shipment\Infrastructure\Persistence\Model\ShipmentModel;

/**
 * Portail client — le compte ne voit QUE les dossiers de sa société (party).
 * Vocabulaire simplifié, aucune donnée interne (marge, agent superviseur, notes internes).
 */
class PortalShipmentController
{
    public function index(Request $request): JsonResponse
    {
        $partyId = $request->user()->party_id;

        $shipments = DB::table('v_shipments_list')
            ->where('client_id', $partyId)
            ->orderByRaw('closed_at IS NULL DESC')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'reference', 'direction', 'mode', 'status', 'origin_locode', 'destination_locode', 'etd', 'eta', 'ata', 'is_delayed', 'closed_at', 'active_containers']);

        return response()->json(['data' => $shipments]);
    }

    public function show(Request $request, string $shipmentId): JsonResponse
    {
        $partyId = $request->user()->party_id;
        $shipment = ShipmentModel::where('client_id', $partyId)->findOrFail($shipmentId);

        $events = DB::table('shipment_events')
            ->where('shipment_id', $shipment->id)
            ->whereIn('type', ['status_change', 'tracking'])
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get(['id', 'title', 'type', 'occurred_at']);

        $packages = DB::table('packages')
            ->leftJoin('containers', 'containers.id', '=', 'packages.container_id')
            ->where('packages.shipment_id', $shipment->id)
            ->orderBy('packages.reference')
            ->get(['packages.reference', 'packages.status', 'containers.number AS container_number', 'packages.delivered_at']);

        $containers = DB::table('container_assignments')
            ->join('containers', 'containers.id', '=', 'container_assignments.container_id')
            ->where('container_assignments.shipment_id', $shipment->id)
            ->get(['containers.number', 'container_assignments.seal_number']);

        return response()->json([
            'id' => $shipment->id,
            'reference' => $shipment->reference,
            'direction' => $shipment->direction->value,
            'mode' => $shipment->mode->value,
            'status' => $shipment->status,
            'origin_locode' => $shipment->origin_locode,
            'destination_locode' => $shipment->destination_locode,
            'etd' => $shipment->etd?->toIso8601String(),
            'eta' => $shipment->eta?->toIso8601String(),
            'ata' => $shipment->ata?->toIso8601String(),
            'containers' => $containers,
            'packages' => $packages,
            'events' => $events,
        ]);
    }
}
