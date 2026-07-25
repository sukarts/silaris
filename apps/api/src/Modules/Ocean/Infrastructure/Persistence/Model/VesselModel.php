<?php

declare(strict_types=1);

namespace Silaris\Modules\Ocean\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;

/** Navires — référentiel partagé entre tenants (pas de scope). */
class VesselModel extends BaseModel
{
    protected $table = 'vessels';

    public function voyages(): HasMany
    {
        return $this->hasMany(VoyageModel::class, 'vessel_id');
    }
}
