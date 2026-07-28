<?php

declare(strict_types=1);

namespace Silaris\Modules\Tracking\Application\Service;

use Illuminate\Support\Facades\DB;
use Silaris\Modules\CarrierConnect\Infrastructure\Support\ShipsGoTranslator;

/**
 * Ingestion d'une expédition poussée par l'agrégateur.
 *
 * Le webhook porte déjà tout ce qu'une interrogation aurait rendu : le
 * traduire sur place évite un aller-retour, et surtout évite d'attendre la
 * prochaine passe planifiée pour voir bouger le dossier.
 */
final readonly class ShipmentUpdateIngestor
{
    public function __construct(
        private TrackingIngestionService $ingestion,
        private ShipsGoTranslator $translator,
    ) {}

    /**
     * @param  array<string, mixed>  $shipment
     * @return int Nombre d'événements nouvellement enregistrés.
     */
    public function ingest(object $subscription, array $shipment): int
    {
        $result = $this->translator->translate($shipment);
        $inserted = $this->ingestion->ingest($subscription, $result);

        DB::table('tracking_subscriptions')->where('id', $subscription->id)->update([
            'last_polled_at' => now(),
            'consecutive_failures' => 0,
            'last_snapshot' => $result->snapshot === [] ? null : json_encode($result->snapshot),
            'updated_at' => now(),
        ]);

        return $inserted;
    }

    /** Pseudo-SCAC de l'agrégateur, pour les correspondances de statut. */
    public function scac(): string
    {
        return ShipsGoTranslator::SCAC;
    }
}
