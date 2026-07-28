<?php

declare(strict_types=1);

namespace Silaris\Modules\Tracking\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Silaris\Modules\Tracking\Application\Service\ShipmentUpdateIngestor;

/**
 * Réception des notifications ShipsGo.
 *
 * Point d'entrée public : il ne peut pas s'appuyer sur une session. La requête
 * est authentifiée par sa signature HMAC-SHA256, calculée sur le corps brut
 * avec le secret du compte, et comparée en temps constant.
 *
 * Le tenant n'est pas déduit de la charge utile — celle-ci vient de l'extérieur
 * — mais de l'abonnement retrouvé en base à partir du numéro suivi. Une
 * notification portant un numéro inconnu est ignorée sans effet.
 */
class ShipsGoWebhookController
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ShipmentUpdateIngestor $ingestor,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('services.shipsgo.webhook_secret');
        if ($secret === '') {
            Log::warning('Webhook ShipsGo reçu sans secret configuré — ignoré.');

            return response()->json(['status' => 'ignored'], 202);
        }

        $raw = $request->getContent();
        $expected = hash_hmac('sha256', $raw, $secret);
        $given = (string) $request->header('X-Shipsgo-Webhook-Signature', '');

        // Comparaison en temps constant : une comparaison naïve laisse fuir la
        // signature attendue, octet par octet, par le temps de réponse.
        if (! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Signature invalide.'], 401);
        }

        $shipment = (array) ($request->json('shipment') ?? []);
        $numbers = array_values(array_filter([
            $shipment['container_number'] ?? null,
            $shipment['booking_number'] ?? null,
            ...array_map(
                static fn ($container) => $container['number'] ?? null,
                (array) ($shipment['containers'] ?? []),
            ),
        ]));

        if ($numbers === []) {
            return response()->json(['status' => 'ignored'], 202);
        }

        // Le tenant vient de l'abonnement, jamais de la charge utile reçue.
        $subscription = DB::table('tracking_subscriptions')
            ->whereIn('subject_number', $numbers)
            ->where('status', 'active')
            ->first();

        if ($subscription === null) {
            return response()->json(['status' => 'unknown'], 202);
        }

        $this->tenant->set($subscription->tenant_id);
        $ingested = $this->ingestor->ingest($subscription, $shipment);

        return response()->json(['status' => 'ok', 'new_events' => $ingested]);
    }
}
