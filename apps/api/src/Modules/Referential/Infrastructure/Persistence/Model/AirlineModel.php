<?php

declare(strict_types=1);

namespace Silaris\Modules\Referential\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;

/** Référentiel global — lecture seule pour l'application (écriture via seeders/admin plateforme). */
class AirlineModel extends BaseModel
{
    protected $table = 'airlines';

    protected $casts = ['is_active' => 'bool'];
}
