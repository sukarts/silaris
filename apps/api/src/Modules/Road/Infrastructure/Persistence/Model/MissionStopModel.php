<?php

declare(strict_types=1);

namespace Silaris\Modules\Road\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;

class MissionStopModel extends BaseModel
{
    protected $table = 'mission_stops';

    protected $casts = ['address' => 'array', 'planned_at' => 'immutable_datetime', 'arrived_at' => 'immutable_datetime'];
}
