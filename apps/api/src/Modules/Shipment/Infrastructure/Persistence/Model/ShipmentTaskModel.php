<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

class ShipmentTaskModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'shipment_tasks';

    protected $casts = ['due_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'requires_approval' => 'bool'];
}
