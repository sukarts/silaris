<?php

declare(strict_types=1);

namespace Silaris\Modules\OdooSync\Application\Service;

use Illuminate\Support\Facades\DB;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;

/** Mapping persistant SILARIS ↔ Odoo (odoo_entity_maps). */
final readonly class EntityMap
{
    public function __construct(private TenantContext $tenant) {}

    public function odooIdOf(string $entityType, string $silarisId): ?int
    {
        $id = DB::table('odoo_entity_maps')
            ->where('tenant_id', $this->tenant->id())
            ->where('entity_type', $entityType)
            ->where('silaris_id', $silarisId)
            ->value('odoo_id');

        return $id !== null ? (int) $id : null;
    }

    public function remember(string $entityType, string $silarisId, string $odooModel, int $odooId, string $direction, ?string $checksum = null): void
    {
        DB::table('odoo_entity_maps')->updateOrInsert(
            ['tenant_id' => $this->tenant->id(), 'entity_type' => $entityType, 'silaris_id' => $silarisId],
            [
                'odoo_model' => $odooModel,
                'odoo_id' => $odooId,
                'checksum' => $checksum,
                $direction === 'push' ? 'last_pushed_at' : 'last_pulled_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}
