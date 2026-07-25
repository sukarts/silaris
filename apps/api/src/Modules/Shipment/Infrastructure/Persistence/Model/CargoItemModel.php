<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

class CargoItemModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'cargo_items';

    protected $casts = ['gross_weight_kg' => 'decimal:3', 'volume_m3' => 'decimal:3', 'declared_value' => 'decimal:2'];
}
