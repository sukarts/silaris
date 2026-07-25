<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

class TransportSegmentModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'transport_segments';

    protected $casts = ['etd' => 'immutable_datetime', 'eta' => 'immutable_datetime', 'atd' => 'immutable_datetime', 'ata' => 'immutable_datetime'];
}
