<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Application\Port;

/** Génère la référence dossier suivante — numérotation sans trou par agence/année. */
interface ReferenceGenerator
{
    public function nextShipmentReference(string $branchId): string;
}
