<?php

declare(strict_types=1);

namespace Silaris\Modules\Shared\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Montant monétaire — entier en sous-unité (centimes) + devise ISO 4217.
 * Jamais de float : toutes les opérations sont entières.
 */
final readonly class Money
{
    private function __construct(
        public int $amountMinor,
        public string $currency,
        public int $decimals,
    ) {}

    public static function of(int $amountMinor, string $currency, int $decimals = 2): self
    {
        if (strlen($currency) !== 3) {
            throw new InvalidArgumentException("Devise invalide : {$currency}");
        }

        return new self($amountMinor, strtoupper($currency), $decimals);
    }

    public static function fromDecimalString(string $amount, string $currency, int $decimals = 2): self
    {
        if (! preg_match('/^-?\d+(\.\d+)?$/', $amount)) {
            throw new InvalidArgumentException("Montant invalide : {$amount}");
        }
        $negative = str_starts_with($amount, '-');
        [$units, $fraction] = array_pad(explode('.', ltrim($amount, '-'), 2), 2, '');
        $fraction = substr(str_pad($fraction, $decimals, '0'), 0, $decimals);
        $minor = (int) $units * (10 ** $decimals) + (int) ($fraction === '' ? 0 : $fraction);

        return self::of($negative ? -$minor : $minor, $currency, $decimals);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return self::of($this->amountMinor + $other->amountMinor, $this->currency, $this->decimals);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return self::of($this->amountMinor - $other->amountMinor, $this->currency, $this->decimals);
    }

    public function isNegative(): bool
    {
        return $this->amountMinor < 0;
    }

    public function toDecimalString(): string
    {
        if ($this->decimals === 0) {
            return (string) $this->amountMinor;
        }
        $abs = abs($this->amountMinor);
        $units = intdiv($abs, 10 ** $this->decimals);
        $fraction = str_pad((string) ($abs % (10 ** $this->decimals)), $this->decimals, '0', STR_PAD_LEFT);

        return ($this->amountMinor < 0 ? '-' : '')."{$units}.{$fraction}";
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Devises incompatibles : {$this->currency} / {$other->currency}");
        }
    }
}
