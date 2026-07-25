<?php

declare(strict_types=1);

namespace Silaris\Modules\Tenancy\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

class CompanyModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'companies';

    protected $casts = ['address' => 'array', 'invoice_settings' => 'array', 'is_active' => 'bool'];

    public function branches(): HasMany
    {
        return $this->hasMany(BranchModel::class, 'company_id');
    }
}
