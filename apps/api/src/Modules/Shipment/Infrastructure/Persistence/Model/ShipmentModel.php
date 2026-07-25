<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;
use Silaris\Modules\Crm\Infrastructure\Persistence\Model\PartyModel;
use Silaris\Modules\Identity\Infrastructure\Persistence\Model\UserModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;
use Silaris\Modules\Shipment\Domain\Model\Enum\Direction;
use Silaris\Modules\Shipment\Domain\Model\Enum\Priority;
use Silaris\Modules\Shipment\Domain\Model\Enum\TransportMode;
use Silaris\Modules\Tenancy\Infrastructure\Persistence\Model\BranchModel;

class ShipmentModel extends BaseModel
{
    use BelongsToTenant;
    use Searchable;

    protected $table = 'shipments';

    protected $casts = [
        'direction' => Direction::class,
        'mode' => TransportMode::class,
        'priority' => Priority::class,
        'etd' => 'immutable_datetime',
        'eta' => 'immutable_datetime',
        'atd' => 'immutable_datetime',
        'ata' => 'immutable_datetime',
        'eta_initial' => 'immutable_datetime',
        'closed_at' => 'immutable_datetime',
        'estimated_cost' => 'decimal:2',
        'estimated_revenue' => 'decimal:2',
        'is_archived' => 'bool',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(PartyModel::class, 'client_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(BranchModel::class, 'branch_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'agent_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEventModel::class, 'shipment_id')->orderByDesc('occurred_at');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ShipmentTaskModel::class, 'shipment_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ShipmentCommentModel::class, 'shipment_id');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(TransportSegmentModel::class, 'shipment_id')->orderBy('position');
    }

    public function cargoItems(): HasMany
    {
        return $this->hasMany(CargoItemModel::class, 'shipment_id');
    }

    /** Index Meilisearch (préfixe Scout appliqué automatiquement). */
    public function searchableAs(): string
    {
        return config('scout.prefix').'shipments';
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'reference' => $this->reference,
            'client_name' => $this->client?->name,
            'origin' => $this->origin_locode,
            'destination' => $this->destination_locode,
        ];
    }
}
