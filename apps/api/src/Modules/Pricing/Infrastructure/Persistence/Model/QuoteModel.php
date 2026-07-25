<?php

declare(strict_types=1);

namespace Silaris\Modules\Pricing\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Silaris\Modules\Crm\Infrastructure\Persistence\Model\PartyModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

class QuoteModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'quotes';

    protected $casts = [
        'cargo_summary' => 'array',
        'total_amount' => 'decimal:2',
        'total_buy_amount' => 'decimal:2',
        'valid_until' => 'immutable_date',
        'sent_at' => 'immutable_datetime',
        'accepted_at' => 'immutable_datetime',
        'rejected_at' => 'immutable_datetime',
    ];

    public function party(): BelongsTo
    {
        return $this->belongsTo(PartyModel::class, 'party_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLineModel::class, 'quote_id')->orderBy('position');
    }
}
