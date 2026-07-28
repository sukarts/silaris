<?php

declare(strict_types=1);

namespace Silaris\Modules\Tenancy\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

class BranchModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'branches';

    protected $casts = ['address' => 'array', 'is_active' => 'bool'];

    /** @return BelongsTo<CompanyModel, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(CompanyModel::class, 'company_id');
    }
}
