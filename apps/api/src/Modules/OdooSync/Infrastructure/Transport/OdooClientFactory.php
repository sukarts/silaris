<?php

declare(strict_types=1);

namespace Silaris\Modules\OdooSync\Infrastructure\Transport;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;

final readonly class OdooClientFactory
{
    public function __construct(private TenantContext $tenant) {}

    public function forCurrentTenant(): OdooClient
    {
        $connection = DB::table('odoo_connections')
            ->where('tenant_id', $this->tenant->id())
            ->where('is_active', true)
            ->first();

        if ($connection === null) {
            throw new RuntimeException('Aucune connexion Odoo active pour ce tenant.');
        }

        return new OdooClient(
            baseUrl: rtrim($connection->base_url, '/'),
            database: $connection->database,
            username: $connection->username,
            apiKey: Crypt::decryptString($connection->api_key),
        );
    }

    public function isConfigured(): bool
    {
        return DB::table('odoo_connections')
            ->where('tenant_id', $this->tenant->id())
            ->where('is_active', true)
            ->exists();
    }
}
