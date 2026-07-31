<?php

declare(strict_types=1);

namespace Silaris\Modules\CarrierConnect\Infrastructure\Connector;

use Illuminate\Support\Facades\Http;
use Silaris\Modules\Air\Domain\Contract\AirTrackingProvider;
use Silaris\Modules\Air\Domain\Contract\AirTrackingResult;
use Silaris\Modules\CarrierConnect\Infrastructure\Support\ExchangeLogger;
use Silaris\Modules\CarrierConnect\Infrastructure\Support\ShipsGoAirTranslator;
use Silaris\Modules\Tracking\Domain\Contract\CarrierUnavailable;
use Throwable;

/**
 * Suivi aérien ShipsGo — même compte, même clé et même base que l'ocean, mais
 * sous `/air/shipments` (160+ compagnies).
 *
 * Comme pour l'ocean, l'expédition se cherche avant de s'enregistrer : la
 * recherche est gratuite, l'enregistrement coûte un crédit. Cette économie
 * n'existe que si l'ordre est respecté.
 */
final readonly class ShipsGoAirConnector implements AirTrackingProvider
{
    /** Pseudo-SCAC de l'agrégateur aérien dans les journaux d'échange. */
    public const SCAC = 'SGOA';

    private const TIMEOUT_SECONDS = 25;

    public function __construct(
        private string $apiKey,
        private string $baseUrl,
        private ExchangeLogger $logger,
        private ShipsGoAirTranslator $translator,
    ) {}

    public function trackByAwb(string $awbNumber, ?string $airlinePrefix = null): AirTrackingResult
    {
        $number = str_replace(['-', ' '], '', $awbNumber);

        $existing = $this->call('GET', '/air/shipments', $number, 'search', [
            'filters[awb_number]' => 'eq:'.$number,
            'take' => 1,
        ]);

        $found = $existing['shipments'][0] ?? null;
        if (is_array($found) && isset($found['id'])) {
            return $this->translator->translate(
                $this->call('GET', '/air/shipments/'.$found['id'], $number, 'track')['shipment'] ?? [],
            );
        }

        $created = $this->call('POST', '/air/shipments', $number, 'subscribe', array_filter([
            'awbNumber' => $awbNumber,
            'airline' => $airlinePrefix,
        ], static fn ($v) => $v !== null && $v !== ''));

        $id = $created['shipment']['id'] ?? null;
        if ($id === null) {
            throw new CarrierUnavailable("ShipsGo n'a pas reconnu la LTA {$awbNumber}.");
        }

        return $this->translator->translate(
            $this->call('GET', '/air/shipments/'.$id, $number, 'track')['shipment'] ?? [],
        );
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
