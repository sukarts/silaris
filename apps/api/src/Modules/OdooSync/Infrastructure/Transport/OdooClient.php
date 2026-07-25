<?php

declare(strict_types=1);

namespace Silaris\Modules\OdooSync\Infrastructure\Transport;

use Illuminate\Support\Facades\Http;

/**
 * Client JSON-RPC Odoo (API externe /jsonrpc, méthode execute_kw).
 * Compatible Odoo 16/17/18 — l'API externe est stable entre versions.
 */
final class OdooClient
{
    private ?int $uid = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $database,
        private readonly string $username,
        private readonly string $apiKey,
    ) {}

    /** @param list<mixed> $args */
    public function execute(string $model, string $method, array $args = [], array $kwargs = []): mixed
    {
        return $this->call('object', 'execute_kw', [
            $this->database, $this->authenticate(), $this->apiKey,
            $model, $method, $args, $kwargs,
        ]);
    }

    public function authenticate(): int
    {
        if ($this->uid !== null) {
            return $this->uid;
        }

        $uid = $this->call('common', 'authenticate', [$this->database, $this->username, $this->apiKey, []]);
        if (! is_int($uid) || $uid <= 0) {
            throw new OdooUnavailable('Authentification Odoo refusée');
        }

        return $this->uid = $uid;
    }

    public function healthcheck(): bool
    {
        try {
            $this->authenticate();

            return true;
        } catch (OdooUnavailable) {
            return false;
        }
    }

    private function call(string $service, string $method, array $args): mixed
    {
        $response = Http::timeout(30)->retry(2, 1000, throw: false)->post("{$this->baseUrl}/jsonrpc", [
            'jsonrpc' => '2.0',
            'method' => 'call',
            'params' => ['service' => $service, 'method' => $method, 'args' => $args],
            'id' => random_int(1, PHP_INT_MAX),
        ]);

        if ($response->failed()) {
            throw new OdooUnavailable("Odoo HTTP {$response->status()}");
        }

        $body = $response->json();
        if (isset($body['error'])) {
            $message = $body['error']['data']['message'] ?? $body['error']['message'] ?? 'Erreur Odoo';
            // Erreur métier Odoo (validation…) ≠ indisponibilité : ne pas retenter aveuglément.
            throw new OdooRequestFailed($message);
        }

        return $body['result'] ?? null;
    }
}
