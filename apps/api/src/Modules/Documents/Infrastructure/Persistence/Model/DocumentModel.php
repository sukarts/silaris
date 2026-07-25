<?php

declare(strict_types=1);

namespace Silaris\Modules\Documents\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

class DocumentModel extends BaseModel
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $table = 'documents';

    protected $casts = ['is_archived' => 'bool'];

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersionModel::class, 'document_id')->orderByDesc('version');
    }

    public function latestVersion(): ?DocumentVersionModel
    {
        /** @var DocumentVersionModel|null */
        return $this->versions()->first();
    }
}
