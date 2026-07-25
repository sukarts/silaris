<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Application\Query\ListShipments;

final readonly class ListShipmentsQuery
{
    /** @param array<string, mixed> $filters status|mode|direction|client_id|agent_id|delayed|search */
    public function __construct(
        public array $filters = [],
        public string $sort = '-eta',
        public int $perPage = 25,
        public ?string $cursor = null,
    ) {}
}
