<?php

declare(strict_types=1);

namespace Silaris\Modules\Billing\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;

class InvoiceLineModel extends BaseModel
{
    protected $table = 'invoice_lines';

    protected $casts = ['quantity' => 'decimal:3', 'unit_price' => 'decimal:4', 'line_total' => 'decimal:2'];
}
