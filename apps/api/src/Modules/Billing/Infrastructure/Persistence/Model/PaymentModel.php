<?php

declare(strict_types=1);

namespace Silaris\Modules\Billing\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Silaris\Modules\Crm\Infrastructure\Persistence\Model\PartyModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

class PaymentModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'payments';

    protected $casts = [
        'amount' => 'decimal:2',
        'received_on' => 'immutable_date',
        'cancelled_at' => 'immutable_datetime',
    ];

    public function party(): BelongsTo
    {
        return $this->belongsTo(PartyModel::class, 'party_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocationModel::class, 'payment_id');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }
}
