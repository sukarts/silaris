<?php

declare(strict_types=1);

namespace Silaris\Modules\Ocean\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;
use Silaris\Modules\Shipment\Infrastructure\Persistence\Model\ShipmentModel;

class PackageModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'packages';

    protected $casts = [
        'weight_kg' => 'decimal:3',
        'volume_m3' => 'decimal:3',
        'received_at' => 'immutable_datetime',
        'stuffed_at' => 'immutable_datetime',
        'unstuffed_at' => 'immutable_datetime',
        'delivered_at' => 'immutable_datetime',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(ShipmentModel::class, 'shipment_id');
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(ContainerModel::class, 'container_id');
    }
}
