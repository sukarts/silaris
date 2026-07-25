<?php

declare(strict_types=1);

namespace Silaris\Modules\Ocean\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;
use Silaris\Modules\Shipment\Infrastructure\Persistence\Model\ShipmentModel;

class ContainerAssignmentModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'container_assignments';

    protected $casts = [
        'vgm_kg' => 'decimal:3',
        'vgm_verified_at' => 'immutable_datetime',
        'free_time_ends_at' => 'immutable_datetime',
        'gate_in_at' => 'immutable_datetime',
        'loaded_at' => 'immutable_datetime',
        'discharged_at' => 'immutable_datetime',
        'gate_out_at' => 'immutable_datetime',
        'returned_at' => 'immutable_datetime',
    ];

    public function container(): BelongsTo
    {
        return $this->belongsTo(ContainerModel::class, 'container_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(ShipmentModel::class, 'shipment_id');
    }
}
