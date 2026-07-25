<?php

declare(strict_types=1);

namespace Silaris\Modules\Road\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

class ProofOfDeliveryModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'proof_of_deliveries';

    protected $casts = ['photo_document_ids' => 'array', 'delivered_at' => 'immutable_datetime'];
}
