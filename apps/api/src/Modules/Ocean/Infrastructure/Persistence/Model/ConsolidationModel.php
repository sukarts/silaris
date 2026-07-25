<?php

declare(strict_types=1);

namespace Silaris\Modules\Ocean\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

class ConsolidationModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'consolidations';

    protected $casts = ['closed_at' => 'immutable_datetime', 'deconsolidated_at' => 'immutable_datetime'];

    public function items(): HasMany
    {
        return $this->hasMany(ConsolidationItemModel::class, 'consolidation_id');
    }
}
