<?php

declare(strict_types=1);

namespace Silaris\Modules\Expense\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Silaris\Modules\Crm\Infrastructure\Persistence\Model\PartyModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

/**
 * @property PartyModel|null $supplier
 */
class ExpenseModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'expenses';

    protected $casts = [
        'amount' => 'decimal:2',
        'invoice_date' => 'immutable_date',
        'due_date' => 'immutable_date',
        'validated_at' => 'immutable_datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(PartyModel::class, 'supplier_id');
    }
}
