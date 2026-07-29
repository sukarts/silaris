<?php

declare(strict_types=1);

use Silaris\Modules\Shared\Domain\Service\AmountInWords;

it('applique les accords du français', function (int $number, string $expected): void {
    expect(AmountInWords::spell($number))->toBe($expected);
})->with([
    // Les cas où une traduction mécanique se trompe.
    [21, 'vingt et un'],
    [71, 'soixante et onze'],
    [80, 'quatre-vingts'],
    [81, 'quatre-vingt-un'],
    [91, 'quatre-vingt-onze'],
    [100, 'cent'],
    [200, 'deux cents'],
    [203, 'deux cent trois'],
    [1000, 'mille'],
    [2000, 'deux mille'],
    [1_000_000, 'un million'],
    [2_000_000, 'deux millions'],
    [16, 'seize'],
    [17, 'dix-sept'],
    [70, 'soixante-dix'],
    [90, 'quatre-vingt-dix'],
]);

it('écrit un montant en francs CFA, sans centimes', function (): void {
    expect(AmountInWords::format(16_380_000, 'XOF'))
        ->toBe('Seize millions trois cent quatre-vingt mille francs CFA');
});

it('accorde le franc au singulier', function (): void {
    expect(AmountInWords::format(1, 'XOF'))->toBe('Un franc CFA');
});

it('écrit les centimes des devises qui en ont', function (): void {
    expect(AmountInWords::format(1250.75, 'EUR'))
        ->toBe('Mille deux cent cinquante euros et soixante-quinze centimes');
});

it('ignore les centimes du franc CFA', function (): void {
    // Le franc CFA ne se subdivise pas dans l'usage courant.
    expect(AmountInWords::format(2500.60, 'XOF'))->toBe('Deux mille cinq cents francs CFA');
});

it('écrit un montant réel de cotation import', function (): void {
    expect(AmountInWords::format(6_717_500, 'XOF'))
        ->toBe('Six millions sept cent dix-sept mille cinq cents francs CFA');
});
