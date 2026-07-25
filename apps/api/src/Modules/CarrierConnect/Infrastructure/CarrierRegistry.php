<?php

declare(strict_types=1);

namespace Silaris\Modules\CarrierConnect\Infrastructure;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Silaris\Modules\CarrierConnect\Infrastructure\Connector\ApiKeyDcsaConnector;
use Silaris\Modules\CarrierConnect\Infrastructure\Connector\FakeCarrierConnector;
use Silaris\Modules\CarrierConnect\Infrastructure\Connector\MaerskConnector;
use Silaris\Modules\CarrierConnect\Infrastructure\Support\ExchangeLogger;
use Silaris\Modules\CarrierConnect\Infrastructure\Support\StatusNormalizer;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Silaris\Modules\Tracking\Domain\Contract\CarrierTrackingProvider;
use Silaris\Modules\Tracking\Domain\Contract\CarrierUnavailable;

/**
 * Résout le connecteur d'une compagnie (SCAC) pour le tenant courant.
 * Credentials configurés → connecteur réel ; sinon hors production → simulateur ;
 * sinon → CarrierUnavailable (le fallback reste la saisie manuelle).
 */
final readonly class CarrierRegistry
{
    /** SCAC → [classe|générique, base URL par défaut, en-tête clé API] */
    private const API_KEY_CARRIERS = [
        'CMDU' => ['https://apis.cma-cgm.net/vessel/trackandtrace/v2', 'KeyId'],
        'HLCU' => ['https://api.hlag.com/hlag/external/v2', 'X-IBM-Client-Id'],
        'ONEY' => ['https://api.one-line.com/oneline/v2', 'apikey'],
        'OOLU' => ['https://api.oocl.com/track-and-trace/v2', 'apiKey'],
        'EGLV' => ['https://api.evergreen-line.com/track/v2', 'x-api-key'],
        'YMLU' => ['https://api.yangming.com/track/v2', 'x-api-key'],
        'COSU' => ['https://api.lines.coscoshipping.com/service/synconhub/tracking/v2', 'X-Coscon-Hmac'],
        'MSCU' => ['https://api.msc.com/track-and-trace/v2', 'x-api-key'],
    ];

    public function __construct(
        private TenantContext $tenant,
        private ExchangeLogger $logger,
        private StatusNormalizer $normalizer,
    ) {}

    public function resolve(string $scac): CarrierTrackingProvider
    {
        $credentials = $this->credentialsFor($scac);

        if ($credentials !== null) {
            if ($scac === 'MAEU') {
                return new MaerskConnector($credentials, $this->logger, $this->normalizer);
            }
            if (isset(self::API_KEY_CARRIERS[$scac])) {
                [$baseUrl, $header] = self::API_KEY_CARRIERS[$scac];

                return new ApiKeyDcsaConnector($scac, $baseUrl, $header, $credentials, $this->logger, $this->normalizer);
            }
            throw new RuntimeException("Connecteur inconnu pour SCAC {$scac}");
        }

        if (! app()->environment('production')) {
            return new FakeCarrierConnector;
        }

        throw new CarrierUnavailable("Aucun credential configuré pour {$scac} — tracking manuel requis.");
    }

    /** @return array<string, mixed>|null */
    private function credentialsFor(string $scac): ?array
    {
        $row = DB::table('carrier_api_credentials')
            ->where('tenant_id', $this->tenant->id())
            ->where('carrier_scac', $scac)
            ->where('is_active', true)
            ->value('credentials');

        if ($row === null) {
            return null;
        }

        $decoded = json_decode(decrypt($row, false) ?: (string) $row, true);

        return is_array($decoded) ? $decoded : null;
    }
}
