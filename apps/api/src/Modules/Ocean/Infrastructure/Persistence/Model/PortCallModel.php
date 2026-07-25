<?php

declare(strict_types=1);

namespace Silaris\Modules\Ocean\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;

class PortCallModel extends BaseModel
{
    protected $table = 'port_calls';

    protected $casts = [
        'eta' => 'immutable_datetime',
        'etd' => 'immutable_datetime',
        'ata' => 'immutable_datetime',
        'atd' => 'immutable_datetime',
    ];
}
