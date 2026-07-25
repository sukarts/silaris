<?php

declare(strict_types=1);

namespace Silaris\Modules\Ocean\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;

class VoyageModel extends BaseModel
{
    protected $table = 'voyages';

    public function vessel(): BelongsTo
    {
        return $this->belongsTo(VesselModel::class, 'vessel_id');
    }

    public function portCalls(): HasMany
    {
        return $this->hasMany(PortCallModel::class, 'voyage_id')->orderBy('position');
    }
}
