<?php

declare(strict_types=1);

namespace Silaris\Modules\Tenancy\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;

class TenantModel extends BaseModel
{
    protected $table = 'tenants';

    protected $casts = ['settings' => 'array', 'is_active' => 'bool'];

    public function companies(): HasMany
    {
        return $this->hasMany(CompanyModel::class, 'tenant_id');
    }
}
