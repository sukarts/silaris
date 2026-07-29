<?php

declare(strict_types=1);

namespace Silaris\Modules\Shared\Domain\Service;

/**
 * Montant en toutes lettres, en français.
 *
 * Une facture pro forma porte le montant en lettres : c'est lui qui fait foi
 * en cas de litige sur un chiffre mal lu ou raturé.
 *
 * Le français impose ses règles d'accord, que la traduction mécanique manque :
 * « vingt » et « cent » prennent un s quand ils sont multipliés sans être
 * suivis d'un autre nombre — quatre-vingts, mais quatre-vingt-un ; deux cents,
 * mais deux cent trois. « Million » et « milliard » sont des noms et
 * s'accordent toujours.
 */
final class AmountInWords
{
    private const UNITS = [
        0 => 'zéro', 1 => 'un', 2 => 'deux', 3 => 'trois', 4 => 'quatre', 5 => 'cinq',
        6 => 'six', 7 => 'sept', 8 => 'huit', 9 => 'neuf', 10 => 'dix', 11 => 'onze',
        12 => 'douze', 13 => 'treize', 14 => 'quatorze', 15 => 'quinze', 16 => 'seize',
    ];

    private const TENS = [
        1 => 'dix', 2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante',
        6 => 'soixante', 7 => 'soixante', 8 => 'quatre-vingt', 9 => 'quatre-vingt',
    ];

    /** Devises sans subdivision courante en usage : le franc CFA s'écrit sans centimes. */
    private const WITHOUT_DECIMALS = ['XOF', 'XAF', 'JPY', 'KRW'];

    public static function format(float $amount, string $currency = 'XOF'): string
    {
        $currency = strtoupper($currency);
        $withoutDecimals = in_array($currency, self::WITHOUT_DECIMALS, true);

        $integer = (int) floor(abs($amount));
        $cents = $withoutDecimals ? 0 : (int) round((abs($amount) - $integer) * 100);

        $words = self::spell($integer).' '.self::currencyName($currency, $integer);

        if ($cents > 0) {
            $words .= ' et '.self::spell($cents).' centime'.($cents > 1 ? 's' : '');
        }

        return ucfirst($amount < 0 ? 'moins '.$words : $words);
    }

    /**
     * Nombre entier en lettres, sans devise.
     *
     * $beforeMille signale que le nombre sert de multiplicateur à « mille ».
     * « Mille » est un adjectif numéral : il retire le s de vingt et de cent,
     * là où « million », qui est un nom, le laisse. D'où quatre-vingt mille,
     * mais quatre-vingts millions.
     */
    public static function spell(int $number, bool $beforeMille = false): string
    {
        if ($number < 0) {
            return 'moins '.self::spell(-$number);
        }
        if ($number <= 16) {
            return self::UNITS[$number];
        }
        if ($number < 100) {
            return self::spellTens($number, $beforeMille);
        }
        if ($number < 1000) {
            return self::spellHundreds($number, $beforeMille);
        }

        return self::spellLarge($number);
    }

    private static function spellTens(int $number, bool $beforeMille = false): string
    {
        $tens = intdiv($number, 10);
        $unit = $number % 10;

        // Soixante-dix et quatre-vingt-dix se disent par addition : 70 = 60+10.
        if ($tens === 7 || $tens === 9) {
            $remainder = $number - ($tens === 7 ? 60 : 80);

            // Soixante et onze, mais quatre-vingt-onze : le « et » ne survit qu'à 71.
            return self::TENS[$tens].($remainder === 11 && $tens === 7 ? ' et onze' : '-'.self::spell($remainder));
        }

        if ($unit === 0) {
            // Quatre-vingts prend un s, sauf suivi d'un nombre ou de mille.
            return self::TENS[$tens].($tens === 8 && ! $beforeMille ? 's' : '');
        }

        // Vingt et un, trente et un… mais quatre-vingt-un.
        $joiner = $unit === 1 && $tens !== 8 ? ' et ' : '-';

        return self::TENS[$tens].$joiner.self::UNITS[$unit];
    }

    private static function spellHundreds(int $number, bool $beforeMille = false): string
    {
        $hundreds = intdiv($number, 100);
        $remainder = $number % 100;

        $prefix = $hundreds === 1 ? 'cent' : self::UNITS[$hundreds].' cent';
        // Deux cents, mais deux cent trois — et deux cent mille.
        if ($remainder === 0) {
            return $hundreds === 1 || $beforeMille ? $prefix : $prefix.'s';
        }

        return $prefix.' '.self::spell($remainder, $beforeMille);
    }

    private static function spellLarge(int $number): string
    {
        foreach ([1_000_000_000 => 'milliard', 1_000_000 => 'million', 1000 => 'mille'] as $scale => $name) {
            if ($number < $scale) {
                continue;
            }

            $count = intdiv($number, $scale);
            $remainder = $number % $scale;

            // « Mille » est invariable ; million et milliard sont des noms.
            $prefix = $scale === 1000
                ? ($count === 1 ? 'mille' : self::spell($count, beforeMille: true).' mille')
                : self::spell($count).' '.$name.($count > 1 ? 's' : '');

            return $remainder === 0 ? $prefix : $prefix.' '.self::spell($remainder);
        }

        return self::UNITS[0];
    }

    private static function currencyName(string $currency, int $amount): string
    {
        $plural = $amount > 1;

        return match ($currency) {
            'XOF', 'XAF' => 'franc'.($plural ? 's' : '').' CFA',
            'EUR' => 'euro'.($plural ? 's' : ''),
            'USD' => 'dollar'.($plural ? 's' : '').' américain'.($plural ? 's' : ''),
            'GBP' => 'livre'.($plural ? 's' : '').' sterling',
            default => $currency,
        };
    }
}
