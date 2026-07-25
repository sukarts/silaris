<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Domain\Model\Enum;

enum Direction: string
{
    case Import = 'import';
    case Export = 'export';

    /** Transbordement / réexpédition — ni import ni export au pays du transitaire. */
    case Transit = 'transit';
}
