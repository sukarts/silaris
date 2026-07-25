<?php

declare(strict_types=1);

namespace Silaris\Modules\Crm\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

class OpportunityModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'opportunities';

    protected $casts = ['estimated_value' => 'decimal:2', 'expected_close_date' => 'immutable_date'];

    public function party(): BelongsTo
    {
        return $this->belongsTo(PartyModel::class, 'party_id');
    }
}
