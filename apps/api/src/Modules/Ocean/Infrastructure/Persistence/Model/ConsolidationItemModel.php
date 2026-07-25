<?php

declare(strict_types=1);

namespace Silaris\Modules\Ocean\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;

class ConsolidationItemModel extends BaseModel
{
    protected $table = 'consolidation_items';

    protected $casts = ['weight_kg' => 'decimal:3', 'volume_m3' => 'decimal:3'];
}
