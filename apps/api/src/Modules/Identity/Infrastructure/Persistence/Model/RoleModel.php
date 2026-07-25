<?php

declare(strict_types=1);

namespace Silaris\Modules\Identity\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;

/** Rôle système (tenant_id NULL) ou personnalisé tenant — pas de scope tenant automatique. */
class RoleModel extends BaseModel
{
    protected $table = 'roles';

    protected $casts = ['is_system' => 'bool'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(PermissionModel::class, 'role_permissions', 'role_id', 'permission_key', 'id', 'key');
    }
}
