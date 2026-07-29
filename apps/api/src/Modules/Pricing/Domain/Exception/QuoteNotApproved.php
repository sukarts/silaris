<?php

declare(strict_types=1);

namespace Silaris\Modules\Pricing\Domain\Exception;

use Silaris\Modules\Shared\Domain\Exception\DomainException;

/** Transmission d'une cotation qui n'a pas été validée en interne. */
final class QuoteNotApproved extends DomainException
{
    public static function make(string $number): self
    {
        return new self("La cotation {$number} doit être validée en interne avant d'être transmise au client.");
    }

    public function errorCode(): string
    {
        return 'quote.not_approved';
    }
}
