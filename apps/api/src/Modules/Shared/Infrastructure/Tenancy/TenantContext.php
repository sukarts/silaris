<?php

declare(strict_types=1);

namespace Silaris\Modules\Shared\Infrastructure\Tenancy;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Contexte tenant de la requête/du job courant.
 * Positionne aussi app.tenant_id sur la connexion PostgreSQL (RLS).
 * Singleton scoped — réinitialisé entre deux jobs par le worker.
 */
final class TenantContext
{
    private ?string $tenantId = null;

    public function set(string $tenantId): void
    {
        $this->tenantId = $tenantId;
        DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]);
    }

    public function id(): string
    {
        if ($this->tenantId === null) {
            throw new RuntimeException('Aucun contexte tenant — requête ou job sans tenant résolu.');
        }

        return $this->tenantId;
    }

    public function idOrNull(): ?string
    {
        return $this->tenantId;
    }

    public function has(): bool
    {
        return $this->tenantId !== null;
    }

    public function forget(): void
    {
        $this->tenantId = null;
        DB::statement("SELECT set_config('app.tenant_id', '', false)");
    }
}
