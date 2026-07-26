<?php

declare(strict_types=1);

namespace Silaris\Modules\Road\Interface\Http\Controller;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Silaris\Modules\Road\Application\Service\DeliveryNoteBuilder;
use Silaris\Modules\Road\Infrastructure\Persistence\Model\MissionModel;
use Silaris\Modules\Tenancy\Application\Service\BrandingResolver;
use Silaris\Modules\Tenancy\Infrastructure\Persistence\Model\CompanyModel;

/**
 * Portail client — bons de livraison des dossiers du client connecté.
 *
 * Le document est le même que celui remis en main propre : il ne mentionne ni
 * transporteur affrété, ni chauffeur, ni véhicule.
 */
class PortalDeliveryNoteController
{
    /** GET /portal/shipments/{id}/delivery-notes — livraisons signées du dossier. */
    public function index(Request $request, string $shipmentId): JsonResponse
    {
        $missions = $this->missionsOf($request, $shipmentId)
            ->map(fn ($mission) => [
                'mission_id' => $mission->id,
                'reference' => $mission->reference,
                'recipient_name' => $mission->pod->recipient_name,
                'delivered_at' => $mission->pod->delivered_at,
            ]);

        return response()->json(['data' => $missions->values()]);
    }

    /** GET /portal/missions/{id}/delivery-note — le PDF signé. */
    public function pdf(Request $request, string $missionId, DeliveryNoteBuilder $builder): Response
    {
        $mission = MissionModel::with(['pod', 'shipment'])->findOrFail($missionId);
        abort_if($mission->pod === null, 404);
        abort_if($mission->shipment?->client_id !== $request->user()->party_id, 404);

        $data = $builder->build($mission);
        $company = CompanyModel::findOrFail($data['company_id']);

        return Pdf::loadView('pdf.delivery-note', [
            ...$data,
            'company' => $company,
            'logo' => app(BrandingResolver::class)->logoDataUri($company),
        ])->download(DeliveryNoteBuilder::fileName($mission));
    }

    /** @return Collection<int, MissionModel> */
    private function missionsOf(Request $request, string $shipmentId): Collection
    {
        return MissionModel::with('pod')
            ->whereHas('shipment', fn ($query) => $query->where('id', $shipmentId)->where('client_id', $request->user()->party_id))
            ->whereHas('pod')
            ->orderByDesc('completed_at')
            ->get();
    }
}
