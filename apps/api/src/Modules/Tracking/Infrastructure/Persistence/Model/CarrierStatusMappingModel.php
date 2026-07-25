<?php

declare(strict_types=1);

namespace Silaris\Modules\Tracking\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;

/** Référentiel global de normalisation statuts → DCSA (pas de scope tenant). */
class CarrierStatusMappingModel extends BaseModel
{
    protected $table = 'carrier_status_mappings';
}
