<?php

declare(strict_types=1);

namespace Silaris\Modules\Pricing\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;

class QuoteLineModel extends BaseModel
{
    protected $table = 'quote_lines';

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:4',
        'buy_price' => 'decimal:4',
        'line_total' => 'decimal:2',
    ];
}
