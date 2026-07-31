<?php

declare(strict_types=1);

namespace Silaris\Modules\Reporting\Infrastructure\Export;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** Un onglet nommé, ses en-têtes et ses lignes — brique des exports tabulaires. */
final class ArraySheet implements FromArray, WithHeadings, WithTitle
{
    /**
     * @param  list<string>  $headings
     * @param  list<array<int, mixed>>  $rows
     */
    public function __construct(
        private readonly string $title,
        private readonly array $headings,
        private readonly array $rows,
    ) {}

    /** @return list<array<int, mixed>> */
    public function array(): array
    {
        return $this->rows;
    }

    /** @return list<string> */
    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        return $this->title;
    }
}
