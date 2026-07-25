<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

class ShipmentCommentModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'shipment_comments';
}
