<?php

declare(strict_types=1);

namespace Silaris\Modules\Referential\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;

/** Référentiel global — lecture seule pour l'application (écriture via seeders/admin plateforme). */
class IncotermModel extends BaseModel
{
    protected $table = 'incoterms';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $casts = ['cost_allocation' => 'array'];
}
