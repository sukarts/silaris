<?php

declare(strict_types=1);

namespace Silaris\Modules\Reporting\Infrastructure\Export;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Rapport de gestion en classeur : un onglet de synthèse, puis le détail de la
 * marge (par mois, par mode) et du chiffre d'affaires (par mois, par société).
 *
 * Le classeur reprend exactement les chiffres de l'écran — mêmes sources,
 * mêmes règles — pour qu'un export et une capture ne se contredisent jamais.
 */
final class BusinessReportExport implements WithMultipleSheets
{
    /** @param array<string, mixed> $payload Sortie de ReportController::payload(). */
    public function __construct(private readonly array $payload) {}

    /** @return list<ArraySheet> */
    public function sheets(): array
    {
        /** @var array<string, mixed> $margin */
        $margin = $this->payload['margin'];
        /** @var array<string, mixed> $revenue */
        $revenue = $this->payload['revenue'];
        /** @var array<string, mixed> $totals */
        $totals = $margin['totals'];
        /** @var array{from: string, to: string} $period */
        $period = $this->payload['period'];

        return [
            new ArraySheet('Synthèse', ['Indicateur', 'Valeur'], [
                ['Période', $period['from'].' → '.$period['to']],
                ['CA vendu (offres gagnées)', $totals['revenue']],
                ['Coût estimé', $totals['cost']],
                ['Marge prévisionnelle', $totals['margin']],
                ['Taux de marge (%)', $totals['rate']],
                ['Offres gagnées', $totals['won_count']],
                ['CA facturé (net des avoirs)', $revenue['total']],
            ]),
            new ArraySheet('Marge par mois', ['Mois', 'CA', 'Coût', 'Marge'],
                array_map(static fn (array $r): array => [$r['month'], $r['revenue'], $r['cost'], $r['margin']], $margin['by_month'])),
            new ArraySheet('Marge par mode', ['Mode', 'CA', 'Coût', 'Marge', 'Taux (%)', 'Offres'],
                array_map(static fn (array $r): array => [$r['mode'], $r['revenue'], $r['cost'], $r['margin'], $r['rate'], $r['won_count']], $margin['by_mode'])),
            new ArraySheet('CA par mois', ['Mois', 'Facturé'],
                array_map(static fn (array $r): array => [$r['month'], $r['invoiced']], $revenue['by_month'])),
            new ArraySheet('CA par société', ['Société', 'Facturé'],
                array_map(static fn (array $r): array => [$r['company'], $r['invoiced']], $revenue['by_company'])),
        ];
    }
}
