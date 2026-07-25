<?php

declare(strict_types=1);

namespace Silaris\Modules\Tracking\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

class TrackingSubscriptionModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'tracking_subscriptions';

    protected $casts = ['last_polled_at' => 'immutable_datetime'];

    public function events(): HasMany
    {
        return $this->hasMany(TrackingEventModel::class, 'subscription_id')->orderByDesc('occurred_at');
    }
}
