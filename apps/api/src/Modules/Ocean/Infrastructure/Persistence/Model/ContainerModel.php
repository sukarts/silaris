<?php

declare(strict_types=1);

namespace Silaris\Modules\Ocean\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

class ContainerModel extends BaseModel
{
    use BelongsToTenant;
    use Searchable;

    protected $table = 'containers';

    protected $casts = ['tare_kg' => 'decimal:3', 'max_payload_kg' => 'decimal:3'];

    public function assignments(): HasMany
    {
        return $this->hasMany(ContainerAssignmentModel::class, 'container_id');
    }

    /** Index Meilisearch (préfixe Scout appliqué automatiquement). */
    public function searchableAs(): string
    {
        return config('scout.prefix').'containers';
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'number' => $this->number,
        ];
    }
}
