<?php

declare(strict_types=1);

namespace Silaris\Modules\CarrierConnect\Infrastructure\Connector;

use Illuminate\Support\Facades\Http;
use Silaris\Modules\CarrierConnect\Infrastructure\Support\ExchangeLogger;
use Silaris\Modules\CarrierConnect\Infrastructure\Support\ShipsGoTranslator;
use Silaris\Modules\Tracking\Domain\Contract\CarrierTrackingProvider;
use Silaris\Modules\Tracking\Domain\Contract\CarrierUnavailable;
use Silaris\Modules\Tracking\Domain\Contract\TrackingResult;
use Throwable;

/**
 * Agrégateur ShipsGo — 135 compagnies actives, dont les lignes africaines.
 *
 * Contrairement aux API interrogeables à volonté, ShipsGo fonctionne par
 * abonnement : une expédition est enregistrée une fois (un crédit), puis
 * relue autant que voulu sans frais jusqu'à la fin du voyage. Le connecteur
 * cherche donc toujours l'expédition avant de l'enregistrer — sans quoi
 * chaque interrogation consommerait un crédit.
 *
 * Il rend l'historique complet des mouvements, avec LOCODE, navire et voyage.
 */
class ShipsGoConnector implements CarrierTrackingProvider
{
    /** Pseudo-SCAC de l'agrégateur dans les journaux et les correspondances de statut. */
    public const SCAC = 'SGOO';

    private const TIMEOUT_SECONDS = 25;

    public function __construct(
        private readonly string $carrierScac,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly ExchangeLogger $logger,
        private readonly ShipsGoTranslator $translator,
    ) {}

    public function trackContainer(string $containerNumber): TrackingResult
    {
        return $this->translator->translate($this->shipmentFor('container_number', $containerNumber));
    }

    /**
     * ShipsGo range le connaissement maître sous `booking_number` : c'est le
     * même champ, le document que la compagnie reconnaît.
     */
    public function trackBillOfLading(string $blNumber): TrackingResult
    {
        return $this->translator->translate($this->shipmentFor('booking_number', $blNumber));
    }

    public function capabilities(): array
    {
        return ['container_tracking', 'bl_tracking'];
    }

    /**
     * Expédition déjà suivie, sinon enregistrée. La recherche est gratuite ;
     * l'enregistrement coûte un crédit, d'où l'ordre.
     *
     * @return array<string, mixed>
     */
    private function shipmentFor(string $field, string $number): array
    {
        $existing = $this->call('GET', '/ocean/shipments', $number, 'search', [
            "filters[{$field}]" => 'eq:'.$number,
            'take' => 1,
        ]);

        $found = $existing['shipments'][0] ?? null;
        if (is_array($found) && isset($found['id'])) {
            return $this->call('GET', '/ocean/shipments/'.$found['id'], $number, 'track')['shipment'] ?? [];
        }

        $created = $this->call('POST', '/ocean/shipments', $number, 'subscribe', [
            $field => $number,
            'carrier' => $this->carrierScac,
        ]);

        $id = $created['shipment']['id'] ?? null;
        if ($id === null) {
            throw new CarrierUnavailable("ShipsGo n'a pas reconnu {$number}.");
        }

        return $this->call('GET', '/ocean/shipments/'.$id, $number, 'track')['shipment'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, string $subject, string $operation, array $payload = []): array
    {
        $started = hrtime(true);
        try {
            $request = Http::withHeaders(['X-Shipsgo-User-Token' => $this->apiKey])
                ->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS);

            $response = $method === 'POST'
                ? $request->post($this->baseUrl.$path, $payload)
                : $request->get($this->baseUrl.$path, $payload);
        } catch (Throwable $e) {
            $this->logger->log(self::SCAC, $operation, $subject, false, null, self::elapsedMs($started), $e->getMessage());

            throw new CarrierUnavailable("ShipsGo injoignable : {$e->getMessage()}");
        }

        $this->logger->log(self::SCAC, $operation, $subject, $response->successful(), $response->status(), self::elapsedMs($started));

        if ($response->failed()) {
            throw new CarrierUnavailable("ShipsGo a répondu {$response->status()} pour {$subject}.");
        }

        return (array) $response->json();
    }

    private static function elapsedMs(int|float $startedNs): int
    {
        return (int) ((hrtime(true) - $startedNs) / 1_000_000);
    }
}
