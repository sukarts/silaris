<?php

declare(strict_types=1);

namespace Silaris\Modules\Shared\Infrastructure;

use Illuminate\Support\ServiceProvider;
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
}
