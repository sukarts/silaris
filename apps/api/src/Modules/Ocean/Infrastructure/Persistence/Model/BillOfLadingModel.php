<?php

declare(strict_types=1);

namespace Silaris\Modules\Ocean\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;
use Silaris\Modules\Shipment\Infrastructure\Persistence\Model\ShipmentModel;

class BillOfLadingModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'bills_of_lading';

    protected $casts = [
        'shipper' => 'array',
        'consignee' => 'array',
        'notify_party' => 'array',
        'gross_weight_kg' => 'decimal:3',
        'volume_m3' => 'decimal:3',
        'issued_at' => 'immutable_datetime',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(ShipmentModel::class, 'shipment_id');
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function houses(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function isMaster(): bool
    {
        return $this->type === 'master';
    }
}
