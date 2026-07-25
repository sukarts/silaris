<?php

declare(strict_types=1);

namespace Silaris\Modules\CarrierConnect\Infrastructure\Connector;

use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Silaris\Modules\CarrierConnect\Infrastructure\Support\ExchangeLogger;
use Silaris\Modules\CarrierConnect\Infrastructure\Support\StatusNormalizer;
use Silaris\Modules\Tracking\Domain\Contract\CarrierTrackingProvider;
use Silaris\Modules\Tracking\Domain\Contract\CarrierUnavailable;
use Silaris\Modules\Tracking\Domain\Contract\TrackingEventDto;
use Silaris\Modules\Tracking\Domain\Contract\TrackingResult;
use Throwable;

/**
 * Base des connecteurs réels — les compagnies majeures exposent des APIs
 * Track & Trace conformes DCSA ; les variantes se limitent à l'URL, l'auth
 * et quelques champs. Chaque compagnie surcharge ce qui diffère.
 */
abstract class AbstractDcsaConnector implements CarrierTrackingProvider
{
    public function __construct(
        protected readonly array $credentials,
        protected readonly ExchangeLogger $logger,
        protected readonly StatusNormalizer $normalizer,
    ) {}

    abstract protected function scac(): string;

    abstract protected function baseUrl(): string;

    /** @return array<string, string> En-têtes d'authentification. */
    abstract protected function authHeaders(): array;

    public function capabilities(): array
    {
        return ['container_tracking', 'bl_tracking'];
    }

    public function trackContainer(string $containerNumber): TrackingResult
    {
        return $this->fetchEvents(['equipmentReference' => $containerNumber], 'track_container', $containerNumber);
    }

    public function trackBillOfLading(string $blNumber): TrackingResult
    {
        return $this->fetchEvents(['transportDocumentReference' => $blNumber], 'track_bl', $blNumber);
    }

    /** @param array<string, string> $query */
    protected function fetchEvents(array $query, string $operation, string $subject): TrackingResult
    {
        $start = hrtime(true);
        try {
            $response = Http::withHeaders($this->authHeaders())
                ->timeout(20)
                ->retry(2, 500, throw: false)
                ->get($this->baseUrl().'/events', [...$query, 'eventType' => 'TRANSPORT,EQUIPMENT', 'limit' => 100]);

            $durationMs = (int) ((hrtime(true) - $start) / 1e6);

            if ($response->failed()) {
                $this->logger->log($this->scac(), $operation, $subject, false, $response->status(), $durationMs, $response->body());
                throw new CarrierUnavailable("{$this->scac()} HTTP {$response->status()}");
            }

            $this->logger->log($this->scac(), $operation, $subject, true, $response->status(), $durationMs);

            return $this->parseDcsaEvents($response->json());
        } catch (CarrierUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger->log($this->scac(), $operation, $subject, false, null, (int) ((hrtime(true) - $start) / 1e6), $e->getMessage());
            throw new CarrierUnavailable("{$this->scac()}: {$e->getMessage()}", previous: $e);
        }
    }

    /** Parse le format d'événements DCSA T&T v2.x. */
    protected function parseDcsaEvents(mixed $payload): TrackingResult
    {
        $events = [];
        foreach ((array) ($payload['events'] ?? $payload ?? []) as $event) {
            if (! is_array($event)) {
                continue;
            }
            $raw = (string) ($event['transportEventTypeCode'] ?? $event['equipmentEventTypeCode'] ?? $event['eventType'] ?? '');
            if ($raw === '') {
                continue;
            }
            $occurredAt = $event['eventDateTime'] ?? $event['eventCreatedDateTime'] ?? null;
            if ($occurredAt === null) {
                continue;
            }
            $events[] = new TrackingEventDto(
                dcsaEventCode: $this->normalizer->normalize($this->scac(), $raw) !== 'UNKN'
                    ? $this->normalizer->normalize($this->scac(), $raw)
                    : strtoupper(substr($raw, 0, 4)),
                rawStatus: $raw,
                locationLocode: $event['eventLocation']['UNLocationCode'] ?? $event['UNLocationCode'] ?? null,
                occurredAt: new DateTimeImmutable((string) $occurredAt),
                vesselImo: isset($event['vessel']['vesselIMONumber']) ? (string) $event['vessel']['vesselIMONumber'] : null,
                rawPayload: $event,
            );
        }

        return new TrackingResult(events: $events);
    }
}
