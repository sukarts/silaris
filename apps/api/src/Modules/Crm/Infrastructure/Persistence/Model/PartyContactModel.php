<?php

declare(strict_types=1);

namespace Silaris\Modules\Crm\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

class PartyContactModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'party_contacts';

    protected $casts = ['is_primary' => 'bool'];
}
