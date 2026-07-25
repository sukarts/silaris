<?php

declare(strict_types=1);

namespace Silaris\Modules\CarrierConnect\Infrastructure\Connector;

use Silaris\Modules\CarrierConnect\Infrastructure\Support\ExchangeLogger;
use Silaris\Modules\CarrierConnect\Infrastructure\Support\StatusNormalizer;

/**
 * Connecteur DCSA authentifié par clé API — paramétré par compagnie.
 * La plupart des compagnies (CMA CGM, Hapag-Lloyd, ONE, OOCL, Evergreen,
 * Yang Ming, COSCO, MSC) exposent leurs APIs T&T derrière une clé API
 * dont seul le nom d'en-tête varie.
 */
class ApiKeyDcsaConnector extends AbstractDcsaConnector
{
    public function __construct(
        private readonly string $carrierScac,
        private readonly string $defaultBaseUrl,
        private readonly string $apiKeyHeader,
        array $credentials,
        ExchangeLogger $logger,
        StatusNormalizer $normalizer,
    ) {
        parent::__construct($credentials, $logger, $normalizer);
    }

    protected function scac(): string
    {
        return $this->carrierScac;
    }

    protected function baseUrl(): string
    {
        return $this->credentials['base_url'] ?? $this->defaultBaseUrl;
    }

    protected function authHeaders(): array
    {
        return [$this->apiKeyHeader => (string) ($this->credentials['api_key'] ?? '')];
    }
}
