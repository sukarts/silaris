<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Domain\Model\Enum;

enum TransportMode: string
{
    case SeaFcl = 'sea_fcl';
    case SeaLcl = 'sea_lcl';
    case Air = 'air';
    case Road = 'road';
    case Multimodal = 'multimodal';

    public function isSea(): bool
    {
        return in_array($this, [self::SeaFcl, self::SeaLcl], true);
    }
}
