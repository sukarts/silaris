<?php

declare(strict_types=1);

namespace Silaris\Modules\Air\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;

class AirTrackingEventModel extends BaseModel
{
    protected $table = 'air_tracking_events';

    public $timestamps = false;

    protected $casts = [
        'occurred_at' => 'immutable_datetime',
        'raw_payload' => 'array',
        'created_at' => 'immutable_datetime',
    ];
}
