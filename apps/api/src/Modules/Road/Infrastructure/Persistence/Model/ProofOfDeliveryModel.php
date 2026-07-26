<?php

declare(strict_types=1);

namespace Silaris\Modules\Road\Infrastructure\Persistence\Model;

use Illuminate\Support\Carbon;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

/**
 * @property string $recipient_name
 * @property string|null $signature_data
 * @property string|null $remarks
 * @property float|null $latitude
 * @property float|null $longitude
 * @property Carbon $delivered_at
 */
class ProofOfDeliveryModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'proof_of_deliveries';

    protected $casts = ['photo_document_ids' => 'array', 'delivered_at' => 'immutable_datetime'];
}
