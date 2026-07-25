<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Domain\Model\Enum;

enum Priority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Critical = 'critical';
}
