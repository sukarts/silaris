<?php

declare(strict_types=1);

namespace Silaris\Modules\Road\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;
use Silaris\Modules\Shipment\Infrastructure\Persistence\Model\ShipmentModel;

class MissionModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'missions';

    protected $casts = [
        'window_start' => 'immutable_datetime',
        'window_end' => 'immutable_datetime',
        'started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(ShipmentModel::class, 'shipment_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(DriverModel::class, 'driver_id');
    }

    public function truck(): BelongsTo
    {
        return $this->belongsTo(TruckModel::class, 'truck_id');
    }

    public function stops(): HasMany
    {
        return $this->hasMany(MissionStopModel::class, 'mission_id')->orderBy('position');
    }

    public function pod(): HasOne
    {
        return $this->hasOne(ProofOfDeliveryModel::class, 'mission_id');
    }
}
