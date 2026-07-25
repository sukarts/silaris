<?php

declare(strict_types=1);

namespace Silaris\Modules\Documents\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

class DocumentVersionModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'document_versions';

    /** s3_key jamais sérialisée — les téléchargements passent par URLs signées. */
    protected $hidden = ['s3_key'];
}
