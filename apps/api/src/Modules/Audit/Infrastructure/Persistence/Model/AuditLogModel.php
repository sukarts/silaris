<?php

declare(strict_types=1);

namespace Silaris\Modules\Audit\Infrastructure\Persistence\Model;

use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

/** Append-only — UPDATE/DELETE révoqués au niveau PostgreSQL. */
class AuditLogModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'audit_logs';

    public $timestamps = false;

    protected $casts = ['old_values' => 'array', 'new_values' => 'array', 'occurred_at' => 'immutable_datetime'];
}
