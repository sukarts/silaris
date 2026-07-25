<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

class ShipmentEventModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'shipment_events';

    public $timestamps = false;

    protected $casts = ['payload' => 'array', 'occurred_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
}
