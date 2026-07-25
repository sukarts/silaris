<?php

declare(strict_types=1);

namespace Silaris\Modules\Tracking\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

/** Table partitionnée — insertion uniquement, jamais d'UPDATE. */
class TrackingEventModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'tracking_events';

    public $timestamps = false;

    protected $casts = ['raw_payload' => 'array', 'occurred_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
}
