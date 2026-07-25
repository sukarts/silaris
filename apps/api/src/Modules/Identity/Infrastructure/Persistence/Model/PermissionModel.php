<?php

declare(strict_types=1);

namespace Silaris\Modules\Identity\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;

class PermissionModel extends BaseModel
{
    protected $table = 'permissions';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;
}
