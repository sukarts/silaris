<?php

declare(strict_types=1);

namespace Silaris\Modules\Shared\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Base des modèles Eloquent SILARIS — PK uuid v7, gardes désactivées
 * (l'écriture passe par les handlers, jamais par mass-assignment de requête).
 */
abstract class BaseModel extends Model
{
    use HasUuids;

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}
