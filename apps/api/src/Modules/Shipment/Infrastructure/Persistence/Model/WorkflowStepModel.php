<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;

class WorkflowStepModel extends BaseModel
{
    protected $table = 'workflow_steps';

    protected $casts = ['transitions' => 'array', 'conditions' => 'array', 'actions' => 'array'];
}
