<?php

declare(strict_types=1);

namespace Silaris\Modules\Referential\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;

/** Référentiel global — lecture seule pour l'application (écriture via seeders/admin plateforme). */
class PortModel extends BaseModel
{
    protected $table = 'ports';

    protected $primaryKey = 'locode';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $casts = ['latitude' => 'decimal:6', 'longitude' => 'decimal:6', 'is_active' => 'bool'];
}
