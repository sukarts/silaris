<?php

declare(strict_types=1);

namespace Silaris\Modules\Pricing\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;

class TariffLineModel extends BaseModel
{
    protected $table = 'tariff_lines';

    protected $casts = [
        'unit_price' => 'decimal:4',
        'minimum' => 'decimal:2',
        'weight_from_kg' => 'decimal:3',
        'weight_to_kg' => 'decimal:3',
    ];
}
