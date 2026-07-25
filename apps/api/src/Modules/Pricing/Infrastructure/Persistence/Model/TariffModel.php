<?php

declare(strict_types=1);

namespace Silaris\Modules\Pricing\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

class TariffModel extends BaseModel
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $table = 'tariffs';

    protected $casts = ['valid_from' => 'immutable_date', 'valid_to' => 'immutable_date', 'is_active' => 'bool'];

    public function lines(): HasMany
    {
        return $this->hasMany(TariffLineModel::class, 'tariff_id');
    }
}
