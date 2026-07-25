<?php

declare(strict_types=1);

namespace Silaris\Modules\Shared\Infrastructure\Persistence\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;

/**
 * Scope global tenant + remplissage automatique de tenant_id à la création.
 * 2e ligne de défense applicative — la RLS PostgreSQL reste la garantie finale.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $context = app(TenantContext::class);
            if ($context->has()) {
                $builder->where($builder->qualifyColumn('tenant_id'), $context->id());
            }
        });

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);
            if ($model->getAttribute('tenant_id') === null && $context->has()) {
                $model->setAttribute('tenant_id', $context->id());
            }
        });
    }
}
