<?php

declare(strict_types=1);

namespace Silaris\Modules\Air\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Silaris\Modules\Referential\Infrastructure\Persistence\Model\AirlineModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;
use Silaris\Modules\Shipment\Infrastructure\Persistence\Model\ShipmentModel;

/**
 * @property ShipmentModel|null $shipment
 * @property AirlineModel|null $airline
 * @property self|null $master
 * @property string|null $chargeable_weight_kg Colonne générée (poids taxable IATA), hors schéma vu par l'analyse statique.
 * @property Collection<int, FlightLegModel> $legs
 * @property Collection<int, self> $houses
 */
class AirWaybillModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'air_waybills';

    protected $casts = [
        'shipper' => 'array',
        'consignee' => 'array',
        'gross_weight_kg' => 'decimal:3',
        'volume_m3' => 'decimal:3',
        'chargeable_weight_kg' => 'decimal:3',
        'issued_at' => 'immutable_datetime',
        'last_tracked_at' => 'immutable_datetime',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(ShipmentModel::class, 'shipment_id');
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(AirlineModel::class, 'airline_id');
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function houses(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function legs(): HasMany
    {
        return $this->hasMany(FlightLegModel::class, 'awb_id')->orderBy('position');
    }

    public function trackingEvents(): HasMany
    {
        return $this->hasMany(AirTrackingEventModel::class, 'awb_id')->orderByDesc('occurred_at');
    }
}
