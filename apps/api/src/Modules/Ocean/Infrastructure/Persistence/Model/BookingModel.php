<?php

declare(strict_types=1);

namespace Silaris\Modules\Ocean\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;
use Silaris\Modules\Shipment\Infrastructure\Persistence\Model\ShipmentModel;

class BookingModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'bookings';

    protected $casts = [
        'vgm_cutoff' => 'immutable_datetime',
        'doc_cutoff' => 'immutable_datetime',
        'port_cutoff' => 'immutable_datetime',
        'confirmed_at' => 'immutable_datetime',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(ShipmentModel::class, 'shipment_id');
    }

    public function voyage(): BelongsTo
    {
        return $this->belongsTo(VoyageModel::class, 'voyage_id');
    }
}
