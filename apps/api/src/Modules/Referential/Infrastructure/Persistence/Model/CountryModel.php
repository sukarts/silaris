<?php

declare(strict_types=1);

namespace Silaris\Modules\Referential\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;

/** Référentiel global — lecture seule pour l'application (écriture via seeders/admin plateforme). */
class CountryModel extends BaseModel
{
    protected $table = 'countries';

    protected $primaryKey = 'code2';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $casts = [];
}
