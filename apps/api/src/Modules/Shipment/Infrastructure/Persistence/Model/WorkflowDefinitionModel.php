<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

class WorkflowDefinitionModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'workflow_definitions';

    protected $casts = ['is_default' => 'bool', 'is_active' => 'bool'];

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStepModel::class, 'workflow_definition_id')->orderBy('position');
    }
}
