<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Silaris\Modules\Pricing\Infrastructure\Provider\PricingServiceProvider;
use Silaris\Modules\Shared\Infrastructure\SharedServiceProvider;
use Silaris\Modules\Shipment\Infrastructure\Provider\ShipmentServiceProvider;

return [
    AppServiceProvider::class,
    SharedServiceProvider::class,
    ShipmentServiceProvider::class,
    PricingServiceProvider::class,
];
