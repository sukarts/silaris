<?php

declare(strict_types=1);

namespace Silaris\Modules\Crm\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

class ComplaintModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'complaints';

    protected $casts = [
        'sla_due_at' => 'immutable_datetime',
        'resolved_at' => 'immutable_datetime',
        'cost' => 'decimal:2',
    ];

    public function party(): BelongsTo
    {
        return $this->belongsTo(PartyModel::class, 'party_id');
    }
}
