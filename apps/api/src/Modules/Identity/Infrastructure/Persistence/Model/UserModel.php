<?php

declare(strict_types=1);

namespace Silaris\Modules\Identity\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;
use Silaris\Modules\Tenancy\Infrastructure\Persistence\Model\BranchModel;

class UserModel extends Authenticatable
{
    use BelongsToTenant;
    use HasApiTokens;
    use HasUuids;
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['password_hash', 'mfa_secret', 'mfa_recovery_codes'];

    protected $casts = [
        'mfa_secret' => 'encrypted',
        'mfa_recovery_codes' => 'array',
        'mfa_enabled' => 'bool',
        'is_active' => 'bool',
        'last_login_at' => 'immutable_datetime',
        'password_changed_at' => 'immutable_datetime',
        'deleted_at' => 'immutable_datetime',
    ];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(RoleModel::class, 'user_roles', 'user_id', 'role_id');
    }

    /** @return BelongsToMany<BranchModel, $this> */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(BranchModel::class, 'user_branches', 'user_id', 'branch_id');
    }

    public function fullName(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    private const PERMISSION_CACHE_TTL = 60;

    /** Rôles à visibilité toutes-agences. */
    private const ALL_BRANCH_ROLES = ['super_admin', 'admin', 'director'];

    /** @return list<string> Permissions effectives (union des rôles), cache 60 s. */
    public function permissionKeys(): array
    {
        return Cache::remember(
            "user_permissions:{$this->id}",
            self::PERMISSION_CACHE_TTL,
            fn () => $this->roles()->with('permissions:key')->get()
                ->flatMap(fn ($role) => $role->permissions->pluck('key'))
                ->unique()->values()->all(),
        );
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissionKeys(), true);
    }

    public function hasAllBranchAccess(): bool
    {
        return $this->roles()->whereIn('key', self::ALL_BRANCH_ROLES)->exists();
    }

    public static function forgetPermissionCache(string $userId): void
    {
        Cache::forget("user_permissions:{$userId}");
    }
}
