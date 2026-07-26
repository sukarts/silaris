<?php

declare(strict_types=1);

namespace Silaris\Modules\Tracking\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Silaris\Modules\Tenancy\Application\Service\BrandingResolver;

/**
 * Suivi public — sans authentification, rate-limité (throttle:public-tracking).
 * Résout dossier / BL / HBL / MBL / AWB / conteneur.
 * AUCUN montant, AUCUN document, AUCUN nom de tiers exposé.
 */
class PublicTrackingController
{
    public function show(Request $request): JsonResponse
    {
        $query = strtoupper(trim((string) $request->query('q', '')));
        if (strlen($query) < 6 || strlen($query) > 32 || ! preg_match('/^[A-Z0-9\/\-]+$/', $query)) {
            return problem(422, 'Numéro invalide', 'https://silaris.app/errors/invalid-tracking-number');
        }

        // Référence colis ? → réponse enrichie du statut individuel du colis.
        $package = DB::connection(config('database.system_connection'))->table('packages')->where('reference', $query)->first();

        $shipmentId = $package !== null ? $package->shipment_id : $this->resolve($query);
        if ($shipmentId === null) {
            return problem(404, 'Aucune expédition trouvée pour ce numéro', 'https://silaris.app/errors/tracking-not-found');
        }

        $shipment = DB::connection(config('database.system_connection'))->table('shipments')->where('id', $shipmentId)->first();

        $events = DB::connection(config('database.system_connection'))->table('shipment_events')
            ->where('shipment_id', $shipmentId)
            ->whereIn('type', ['status_change', 'tracking'])
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get(['title', 'type', 'occurred_at']);

        $packagePayload = null;
        if ($package !== null) {
            $containerNumber = $package->container_id !== null
                ? DB::connection(config('database.system_connection'))->table('containers')->where('id', $package->container_id)->value('number')
                : null;
            $packageStatusLabels = [
                'received' => 'Reçu à l\'entrepôt',
                'stuffed' => $containerNumber ? "Embarqué dans le conteneur {$containerNumber}" : 'Embarqué en conteneur',
                'unstuffed' => 'Arrivé — sorti du conteneur',
                'delivered' => 'Remis au destinataire',
            ];
            $packagePayload = [
                'reference' => $package->reference,
                'status' => $package->status,
                'status_label' => $packageStatusLabels[$package->status] ?? $package->status,
                'container_number' => $containerNumber,
                'received_at' => $package->received_at,
                'stuffed_at' => $package->stuffed_at,
                'unstuffed_at' => $package->unstuffed_at,
                'delivered_at' => $package->delivered_at,
            ];
        }

        $system = DB::connection(config('database.system_connection'));
        $portNames = $system->table('ports')
            ->whereIn('locode', [$shipment->origin_locode, $shipment->destination_locode])
            ->pluck('name', 'locode');
        // Navire courant : dernier événement de tracking portant une info navire.
        $vesselName = $system->table('tracking_events')
            ->where('shipment_id', $shipmentId)
            ->orderByDesc('occurred_at')
            ->limit(20)
            ->get(['raw_payload'])
            ->map(fn ($e) => json_decode((string) $e->raw_payload, true)['current_vessel_name'] ?? null)
            ->first(fn ($name) => is_string($name) && $name !== '');
        $tenantName = $system->table('tenants')->where('id', $shipment->tenant_id)->value('name');
        // Marque visible = celle du transitaire (SILARIS fournit la solution, pas l'enseigne).
        $branding = $system->table('companies')->where('id', $shipment->company_id)
            ->first(['legal_name', 'logo_document_id']);

        return response()->json([
            'package' => $packagePayload,
            'reference' => $shipment->reference,
            'status' => $shipment->status,
            'mode' => $shipment->mode,
            'origin_locode' => $shipment->origin_locode,
            'destination_locode' => $shipment->destination_locode,
            'origin_name' => $portNames[$shipment->origin_locode] ?? null,
            'destination_name' => $portNames[$shipment->destination_locode] ?? null,
            'vessel_name' => $vesselName,
            'tenant_name' => $branding->legal_name ?? $tenantName,
            'logo_url' => app(BrandingResolver::class)->logoUrl($branding->logo_document_id ?? null),
            'eta' => $shipment->eta,
            'ata' => $shipment->ata,
            'events' => $events,
        ]);
    }

    /** Résolution multi-type — requêtes SANS scope tenant (recherche globale contrôlée). */
    private function resolve(string $query): ?string
    {
        return DB::connection(config('database.system_connection'))->table('shipments')->where('reference', $query)->value('id')
            ?? DB::connection(config('database.system_connection'))->table('bills_of_lading')->where('number', $query)->value('shipment_id')
            ?? DB::connection(config('database.system_connection'))->table('air_waybills')->where('number', str_replace(['-', '/'], '', $query))->value('shipment_id')
            ?? DB::connection(config('database.system_connection'))->table('container_assignments')
                ->join('containers', 'containers.id', '=', 'container_assignments.container_id')
                ->where('containers.number', str_replace([' ', '-'], '', $query))
                ->orderByDesc('container_assignments.created_at')
                ->value('container_assignments.shipment_id');
    }
}
