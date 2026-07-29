<?php

declare(strict_types=1);

namespace Silaris\Modules\Tenancy\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

/** Service du transitaire — import, export, livraison, aérien… */
class ServiceModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'services';

    protected $casts = ['is_active' => 'bool'];
}
