<?php

declare(strict_types=1);

namespace Silaris\Modules\Road\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

class TrailerModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'trailers';

    protected $casts = ['is_active' => 'bool'];
}
