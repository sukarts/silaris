<?php

declare(strict_types=1);

namespace Silaris\Modules\Road\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

class DriverModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'drivers';

    protected $casts = ['license_expiry' => 'immutable_date', 'is_active' => 'bool'];
}
