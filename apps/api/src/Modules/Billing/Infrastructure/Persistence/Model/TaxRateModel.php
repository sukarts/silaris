<?php

declare(strict_types=1);

namespace Silaris\Modules\Billing\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

/** Miroir des taxes Odoo — écrit uniquement par OdooSync. */
class TaxRateModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'tax_rates';

    protected $casts = ['rate_percent' => 'decimal:3', 'is_active' => 'bool'];
}
