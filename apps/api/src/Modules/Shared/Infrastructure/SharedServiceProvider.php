<?php

declare(strict_types=1);

namespace Silaris\Modules\Shared\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Silaris\Modules\Audit\Application\Service\AuditRecorder;
use Silaris\Modules\Shared\Application\Bus\CommandBus;
use Silaris\Modules\Shared\Application\Bus\QueryBus;
use Silaris\Modules\Shared\Infrastructure\Auth\CurrentUser;
use Silaris\Modules\Shared\Infrastructure\Bus\SimpleQueryBus;
use Silaris\Modules\Shared\Infrastructure\Bus\TransactionalCommandBus;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;

class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(CurrentUser::class);
        $this->app->bind(CommandBus::class, TransactionalCommandBus::class);
        $this->app->bind(QueryBus::class, SimpleQueryBus::class);
    }

    public function boot(): void
    {
        // Journal d'audit automatique : chaque mutation Eloquent des modèles
        // métier (namespace Silaris\) écrit une ligne audit_logs. Filtres,
        // masquage des secrets et fail-open gérés par AuditRecorder.
        foreach (['created', 'updated', 'deleted'] as $action) {
            Event::listen("eloquent.{$action}: *", function (string $event, array $payload) use ($action): void {
                if (($payload[0] ?? null) instanceof Model) {
                    $this->app->make(AuditRecorder::class)->record($action, $payload[0]);
                }
            });
        }
    }
}
