<?php

declare(strict_types=1);

namespace Silaris\Modules\Pricing\Domain\Service;

use DateTimeImmutable;

/** Port — grilles tarifaires applicables (implémentation Infrastructure). */
interface TariffProvider
{
    /** @return list<TariffLineDto> */
    public function linesFor(
        string $mode,
        string $originLocode,
        string $destinationLocode,
        string $side,
        DateTimeImmutable $date,
        ?string $partyId = null,
    ): array;
}
