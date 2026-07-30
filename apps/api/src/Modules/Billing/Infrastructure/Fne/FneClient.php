<?php

declare(strict_types=1);

namespace Silaris\Modules\Billing\Infrastructure\Fne;

use Illuminate\Support\Facades\Http;
use Silaris\Modules\Billing\Domain\Fne\FneCertificationFailed;
use Throwable;

/**
 * Appel de certification à la plateforme FNE de la DGI.
 *
 * Un seul point de sortie réseau : il porte la clé d'API de la société — jamais
 * une clé partagée — et distingue les deux échecs qui n'appellent pas la même
 * conduite. Une plateforme injoignable se retente ; un refus de la DGI se
 * corrige. Confondre les deux ferait attendre là où il faut agir, ou l'inverse.
 */
final class FneClient
{
    private const SIGN_PATH = '/external/invoices/sign';

    /**
     * Certifie une facture. Rend la réponse brute de la DGI.
     *
     * @param  array<string, mixed>  $payload
     * @return array{reference: string, token: string, balance_sticker: int|null, raw: array<string, mixed>}
     */
    public function sign(string $apiKey, array $payload): array
    {
        $baseUrl = $this->baseUrl();

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout((int) config('services.fne.timeout', 20))
                ->post($baseUrl.self::SIGN_PATH, $payload);
        } catch (Throwable $e) {
            throw FneCertificationFailed::unreachable($e->getMessage());
        }

        // 4xx : la DGI a compris et refusé — le corps porte le motif.
        if ($response->clientError()) {
            $body = $response->json();
            $reason = is_array($body)
                ? (string) ($body['message'] ?? $body['error'] ?? $response->body())
                : $response->body();

            throw FneCertificationFailed::rejected($reason !== '' ? $reason : "HTTP {$response->status()}");
        }

        // 5xx ou absence de réponse exploitable : indisponibilité, à retenter.
        if ($response->failed()) {
            throw FneCertificationFailed::unreachable("HTTP {$response->status()}");
        }

        $data = $response->json();
        if (! is_array($data) || ($data['reference'] ?? '') === '') {
            throw FneCertificationFailed::unreachable('réponse illisible de la plateforme');
        }

        return [
            'reference' => (string) $data['reference'],
            'token' => (string) ($data['token'] ?? ''),
            'balance_sticker' => isset($data['balance_sticker']) ? (int) $data['balance_sticker'] : null,
            'raw' => $data,
        ];
    }

    /** URL de la plateforme selon l'environnement configuré. */
    private function baseUrl(): string
    {
        $env = (string) config('services.fne.env', 'test');
        $url = (string) config("services.fne.base_url.{$env}", '');

        if ($url === '') {
            throw FneCertificationFailed::unreachable("aucune URL FNE configurée pour l'environnement « {$env} »");
        }

        return rtrim($url, '/');
    }
}
