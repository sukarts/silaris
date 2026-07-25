<?php

declare(strict_types=1);

use Silaris\Modules\Shared\Domain\ValueObject\Money;

test('parsing décimal exact sans float', function (): void {
    expect(Money::fromDecimalString('1470000.50', 'XOF')->amountMinor)->toBe(147000050)
        ->and(Money::fromDecimalString('0.10', 'EUR')->amountMinor)->toBe(10)
        ->and(Money::fromDecimalString('-5.05', 'EUR')->amountMinor)->toBe(-505);
});

test('addition et soustraction même devise', function (): void {
    $total = Money::fromDecimalString('1470000.50', 'XOF')->add(Money::fromDecimalString('285000.25', 'XOF'));
    expect($total->toDecimalString())->toBe('1755000.75');
});

test('devises différentes rejetées', function (): void {
    Money::of(100, 'EUR')->add(Money::of(100, 'USD'));
})->throws(InvalidArgumentException::class);

test('devise à zéro décimale', function (): void {
    expect(Money::fromDecimalString('84200000', 'XOF', 0)->toDecimalString())->toBe('84200000');
});
