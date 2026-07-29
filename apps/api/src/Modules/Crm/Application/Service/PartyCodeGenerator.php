<?php

declare(strict_types=1);

namespace Silaris\Modules\Crm\Application\Service;

use Illuminate\Support\Facades\DB;

/**
 * Code interne d'un tiers.
 *
 * Ce code part sur les factures, les cotations et la synchronisation
 * comptable : il doit rester stable, reconnaissable et triable. Laissé à la
 * saisie, il produisait des fiches hors nomenclature — « DAI », « D&F » — qu'on
 * ne peut ni classer ni rapprocher d'un relevé, et qui se confondent avec le
 * nom du client sur un document.
 */
final class PartyCodeGenerator
{
    private const PREFIXES = ['client' => 'CLI', 'prospect' => 'PRO', 'supplier' => 'FOU'];

    /** CLI-0001, PRO-0042, FOU-0007. */
    public const PATTERN = '/^(CLI|PRO|FOU|TRS)-\d{4,}$/';

    /** Préfixe par type et séquence sans trou, comme la numérotation des factures. */
    public static function next(string $tenantId, string $type): string
    {
        $sequence = DB::selectOne('SELECT next_sequence(?, ?) AS value', [
            $tenantId, 'party:'.$type,
        ])->value;

        return sprintf('%s-%04d', self::PREFIXES[$type] ?? 'TRS', $sequence);
    }

    public static function isConform(string $code): bool
    {
        return preg_match(self::PATTERN, $code) === 1;
    }
}
