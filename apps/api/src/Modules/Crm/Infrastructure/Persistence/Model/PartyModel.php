<?php

declare(strict_types=1);

namespace Silaris\Modules\Crm\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

class PartyModel extends BaseModel
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $table = 'parties';

    protected $casts = [
        'notification_prefs' => 'array',
        'tags' => 'array',
        'credit_limit' => 'decimal:2',
        'converted_from_prospect_at' => 'immutable_datetime',
    ];

    public function contacts(): HasMany
    {
        return $this->hasMany(PartyContactModel::class, 'party_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(PartyAddressModel::class, 'party_id');
    }

    public function isClient(): bool
    {
        return $this->type === 'client';
    }
}
