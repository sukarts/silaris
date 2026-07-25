<?php

declare(strict_types=1);

namespace Silaris\Modules\Crm\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

/** Compte portail client — guard d'authentification distinct des utilisateurs internes. */
class PortalAccountModel extends Authenticatable
{
    use BelongsToTenant;
    use HasApiTokens;
    use HasUuids;

    protected $table = 'portal_accounts';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['password_hash'];

    protected $casts = ['notification_prefs' => 'array', 'is_active' => 'bool', 'last_login_at' => 'immutable_datetime'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(PartyModel::class, 'party_id');
    }
}
