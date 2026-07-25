<?php

declare(strict_types=1);

namespace Silaris\Modules\Air\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;

class FlightLegModel extends BaseModel
{
    protected $table = 'flight_legs';

    protected $casts = ['departure_at' => 'immutable_datetime', 'arrival_at' => 'immutable_datetime'];
}
