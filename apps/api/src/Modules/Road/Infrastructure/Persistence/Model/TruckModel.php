<?php

declare(strict_types=1);

namespace Silaris\Modules\Road\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

class TruckModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'trucks';

    protected $casts = ['capacity_kg' => 'decimal:3', 'inspection_due' => 'immutable_date', 'insurance_due' => 'immutable_date', 'is_active' => 'bool'];
}
