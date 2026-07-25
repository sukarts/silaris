<?php

declare(strict_types=1);

namespace Silaris\Modules\Pricing\Infrastructure\Provider;

use Illuminate\Support\ServiceProvider;
use Silaris\Modules\Pricing\Domain\Service\TariffProvider;
use Silaris\Modules\Pricing\Infrastructure\Persistence\EloquentTariffProvider;

class PricingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TariffProvider::class, EloquentTariffProvider::class);
    }
}
