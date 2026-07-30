<?php

declare(strict_types=1);

namespace Silaris\Modules\Billing\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;
use Silaris\Modules\Crm\Infrastructure\Persistence\Model\PartyModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;
use Silaris\Modules\Shipment\Infrastructure\Persistence\Model\ShipmentModel;

class InvoiceModel extends BaseModel
{
    use BelongsToTenant;
    use Searchable;

    protected $table = 'invoices';

    protected $casts = [
        'total_excl_tax' => 'decimal:2',
        'total_tax' => 'decimal:2',
        'total_incl_tax' => 'decimal:2',
        'issue_date' => 'immutable_date',
        'due_date' => 'immutable_date',
        'validated_at' => 'immutable_datetime',
        'fne_certified_at' => 'immutable_datetime',
    ];

    public function party(): BelongsTo
    {
        return $this->belongsTo(PartyModel::class, 'party_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(ShipmentModel::class, 'shipment_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLineModel::class, 'invoice_id')->orderBy('position');
    }

    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_invoice_id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /** Index Meilisearch (préfixe Scout appliqué automatiquement). */
    public function searchableAs(): string
    {
        return config('scout.prefix').'invoices';
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'number' => $this->number,
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return $this->number !== null;
    }
}
