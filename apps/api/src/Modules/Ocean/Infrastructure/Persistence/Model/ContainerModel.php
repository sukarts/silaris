<?php

declare(strict_types=1);

namespace Silaris\Modules\Ocean\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

class ContainerModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'containers';

    protected $casts = ['tare_kg' => 'decimal:3', 'max_payload_kg' => 'decimal:3'];

    public function assignments(): HasMany
    {
        return $this->hasMany(ContainerAssignmentModel::class, 'container_id');
    }
}
