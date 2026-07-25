<?php

declare(strict_types=1);

namespace Silaris\Modules\Shared\Domain\ValueObject;

use InvalidArgumentException;

/**
 * UN/LOCODE — 2 lettres pays + 3 alphanumériques lieu (ex. CIABJ, FRCDG).
 */
final readonly class Locode
{
    private function __construct(public string $value) {}

    public static function of(string $value): self
    {
        $normalized = strtoupper(trim($value));
        if (! preg_match('/^[A-Z]{2}[A-Z2-9]{3}$/', $normalized)) {
            throw new InvalidArgumentException("UN/LOCODE invalide : {$value}");
        }

        return new self($normalized);
    }

    public function countryCode(): string
    {
        return substr($this->value, 0, 2);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
