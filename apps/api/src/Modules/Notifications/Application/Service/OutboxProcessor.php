<?php

declare(strict_types=1);

namespace Silaris\Modules\Notifications\Application\Service;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Throwable;

/**
 * Consomme l'outbox transactionnelle : traduit les événements métier en
 * notifications client (email), puis marque published_at. Un événement en
 * échec reste non publié (attempts++, last_error) et sera retenté jusqu'à
 * MAX_ATTEMPTS ; les types sans notification associée sont publiés sans effet.
 */
final class OutboxProcessor
{
    private const MAX_ATTEMPTS = 5;

    private const BATCH = 50;

    /** step du workflow → event_type notification. */
    private const STEP_EVENTS = [
        'departure' => 'departure',
        'arrival' => 'arrival',
        'customs' => 'customs',
        'delivery' => 'delivery',
    ];

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly TenantContext $context,
    ) {}

    /** @return array{processed: int, notified: int, failed: int} */
    public function run(): array
    {
        $events = DB::table('outbox_events')
            ->whereNull('published_at')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->orderBy('occurred_at')
            ->limit(self::BATCH)
            ->get();

        $processed = $notified = $failed = 0;

        foreach ($events as $event) {
            $this->context->set($event->tenant_id);
            try {
                $didNotify = $this->handle($event);
                DB::table('outbox_events')->where('id', $event->id)->update([
                    'published_at' => now(),
                    'attempts' => $event->attempts + 1,
                    'last_error' => null,
                ]);
                $processed++;
                if ($didNotify) {
                    $notified++;
                }
            } catch (Throwable $e) {
                DB::table('outbox_events')->where('id', $event->id)->update([
                    'attempts' => $event->attempts + 1,
                    'last_error' => mb_substr($e->getMessage(), 0, 1000),
                ]);
                $failed++;
            } finally {
                $this->context->forget();
            }
        }

        return ['processed' => $processed, 'notified' => $notified, 'failed' => $failed];
    }

    private function handle(object $event): bool
    {
        $payload = json_decode((string) $event->payload, true) ?: [];

        return match (true) {
            $event->event_type === 'shipment.delay_detected' => $this->notifyShipment(
                $event, 'delay', ['delay_hours' => $payload['delay_hours'] ?? null, 'eta' => $this->formatDate($payload['new_eta'] ?? null)],
            ),
            $event->event_type === 'shipment.step_advanced' && isset(self::STEP_EVENTS[$payload['to'] ?? '']) => $this->notifyShipment(
                $event, self::STEP_EVENTS[$payload['to']], [],
            ),
            $event->event_type === 'invoice.validated' => $this->notifyInvoice($event, $payload),
            default => false, // événement sans notification associée — publié sans effet
        };
    }

    /** @param array<string, string|int|float|null> $extra */
    private function notifyShipment(object $event, string $eventType, array $extra): bool
    {
        $shipment = DB::table('shipments')->where('id', $event->aggregate_id)
            ->first(['id', 'reference', 'client_id', 'eta']);
        if ($shipment === null || $shipment->client_id === null) {
            return false;
        }

        $variables = array_merge([
            'reference' => $shipment->reference,
            'eta' => $extra['eta'] ?? $this->formatDate($shipment->eta),
        ], $extra);

        return $this->dispatcher->dispatchToClient(
            $event->tenant_id, $eventType, $shipment->id, $shipment->client_id, $variables,
        ) !== null;
    }

    /** @param array<string, mixed> $payload */
    private function notifyInvoice(object $event, array $payload): bool
    {
        $clientId = $payload['client_id'] ?? null;
        if ($clientId === null) {
            return false;
        }

        $reference = isset($payload['shipment_id'])
            ? DB::table('shipments')->where('id', $payload['shipment_id'])->value('reference')
            : null;

        return $this->dispatcher->dispatchToClient(
            $event->tenant_id, 'invoice_available', $payload['shipment_id'] ?? null, (string) $clientId, [
                'invoice_number' => $payload['number'] ?? null,
                'total' => $payload['total'] ?? null,
                'currency' => $payload['currency'] ?? null,
                'reference' => $reference ?? '—',
            ],
        ) !== null;
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->format('d/m/Y');
        } catch (Throwable) {
            return (string) $value;
        }
    }
}
