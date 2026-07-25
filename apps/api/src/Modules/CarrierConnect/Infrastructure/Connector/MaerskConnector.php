<?php

declare(strict_types=1);

namespace Silaris\Modules\CarrierConnect\Infrastructure\Connector;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Silaris\Modules\Tracking\Domain\Contract\CarrierUnavailable;

class MaerskConnector extends AbstractDcsaConnector
{
    protected function scac(): string
    {
        return 'MAEU';
    }

    protected function baseUrl(): string
    {
        return $this->credentials['base_url'] ?? 'https://api.maersk.com/track-and-trace-private';
    }

    protected function authHeaders(): array
    {
        return ['Authorization' => 'Bearer '.$this->accessToken(), 'Consumer-Key' => (string) ($this->credentials['consumer_key'] ?? '')];
    }

    /** OAuth2 client credentials — token mis en cache jusqu'à expiration. */
    private function accessToken(): string
    {
        return Cache::remember("maersk_token:{$this->credentials['client_id']}", 3000, function (): string {
            $response = Http::asForm()->post(
                $this->credentials['token_url'] ?? 'https://api.maersk.com/customer-identity/oauth/v2/access_token',
                [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->credentials['client_id'] ?? '',
                    'client_secret' => $this->credentials['client_secret'] ?? '',
                ],
            );
            if ($response->failed()) {
                throw new CarrierUnavailable('MAEU OAuth: HTTP '.$response->status());
            }

            return (string) $response->json('access_token');
        });
    }
}
