<?php

declare(strict_types=1);

namespace Silaris\Modules\Ocean\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Scout\Searchable;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;
use Silaris\Modules\Shipment\Infrastructure\Persistence\Model\ShipmentModel;

class BookingModel extends BaseModel
{
    use BelongsToTenant;
    use Searchable;

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

    /** Index Meilisearch (préfixe Scout appliqué automatiquement). */
    public function searchableAs(): string
    {
        return config('scout.prefix').'bookings';
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'booking_number' => $this->booking_number,
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return $this->booking_number !== null;
    }
}
