<?php

declare(strict_types=1);

namespace Silaris\Modules\Ocean\Application\Service;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Surestaries et détention.
 *
 * La compagnie facture deux immobilisations, avec pour chacune sa franchise :
 *
 *  - la SURESTARIE, tant que le conteneur reste au terminal — à l'import du
 *    déchargement à la sortie du port, à l'export de l'entrée du plein à son
 *    chargement sur le navire ;
 *  - la DÉTENTION, tant que le conteneur est chez le client — à l'import de la
 *    sortie du port à la restitution du vide, à l'export de l'enlèvement du vide
 *    à l'entrée du plein.
 *
 * Chaque compteur a son jalon d'ouverture, son jalon de fermeture et sa
 * franchise. Les jalons viennent du suivi transporteur ; les franchises se
 * négocient par document — connaissement à l'import, booking à l'export.
 */
final readonly class FreeTimeTracker
{
    /**
     * Jalons DCSA ouvrant et fermant chaque compteur, par sens.
     *
     * @var array<string, array<string, array{start: string, stop: string}>>
     */
    private const METER = [
        'demurrage' => [
            'import' => ['start' => 'DISC', 'stop' => 'GTOT'],
            'export' => ['start' => 'GTIN', 'stop' => 'LOAD'],
        ],
        'detention' => [
            'import' => ['start' => 'GTOT', 'stop' => 'RETU'],
            'export' => ['start' => 'GTOT', 'stop' => 'GTIN'],
        ],
    ];

    /** Colonne d'horodatage de l'affectation, par code DCSA. */
    private const MILESTONE_COLUMNS = [
        'GTIN' => 'gate_in_at',
        'LOAD' => 'loaded_at',
        'DISC' => 'discharged_at',
        'GTOT' => 'gate_out_at',
        'RETU' => 'returned_at',
    ];

    /** @var list<string> */
    public const KINDS = ['demurrage', 'detention'];

    /**
     * Reporte un jalon de suivi sur l'affectation, puis recalcule les échéances.
     * Un même jalon peut fermer un compteur et en ouvrir un autre — la sortie du
     * port arrête la surestarie et démarre la détention.
     */
    public function recordMilestone(string $shipmentId, ?string $containerNumber, string $dcsaCode, Carbon $occurredAt): void
    {
        $column = self::MILESTONE_COLUMNS[$dcsaCode] ?? null;
        if ($column === null) {
            return;
        }

        $assignments = DB::table('container_assignments')
            ->join('containers', 'containers.id', '=', 'container_assignments.container_id')
            ->where('container_assignments.shipment_id', $shipmentId)
            ->when($containerNumber !== null, fn ($query) => $query->where('containers.number', $containerNumber))
            ->get(['container_assignments.id', 'container_assignments.'.$column.' AS current']);

        foreach ($assignments as $assignment) {
            // Le premier passage fait foi : un relevé rejoué ne repousse pas une
            // échéance déjà courue.
            if ($assignment->current !== null) {
                continue;
            }

            DB::table('container_assignments')->where('id', $assignment->id)
                ->update([$column => $occurredAt, 'updated_at' => now()]);

            $this->refreshDeadlines((string) $assignment->id);
        }
    }

    /** Recalcule les deux échéances d'une affectation depuis ses franchises. */
    public function refreshDeadlines(string $assignmentId): void
    {
        $row = DB::table('container_assignments AS ca')
            ->join('shipments AS s', 's.id', '=', 'ca.shipment_id')
            ->where('ca.id', $assignmentId)
            ->first(['ca.id', 'ca.shipment_id', 'ca.discharged_at', 'ca.gate_out_at', 'ca.gate_in_at', 'ca.loaded_at', 's.direction']);

        if ($row === null) {
            return;
        }

        $direction = $row->direction === 'export' ? 'export' : 'import';
        $days = $this->freeTimeDaysOf($row->shipment_id, $direction);

        $update = ['updated_at' => now()];
        foreach (self::KINDS as $kind) {
            $startColumn = self::MILESTONE_COLUMNS[self::METER[$kind][$direction]['start']];
            $startedAt = $row->{$startColumn} ?? null;
            $free = $days[$kind];

            $update["{$kind}_days"] = $free;
            $update["{$kind}_ends_at"] = $free === null || $startedAt === null
                ? null
                : Carbon::parse($startedAt)->addDays($free);
        }

        DB::table('container_assignments')->where('id', $row->id)->update($update);
    }

    /**
     * Franchises du document porteur — connaissement maître à l'import, booking
     * à l'export.
     *
     * @return array{demurrage: ?int, detention: ?int}
     */
    public function freeTimeDaysOf(string $shipmentId, string $direction): array
    {
        $row = $direction === 'export'
            ? DB::table('bookings')->where('shipment_id', $shipmentId)
                ->orderByDesc('created_at')->first(['demurrage_free_days', 'detention_free_days'])
            : DB::table('bills_of_lading')->where('shipment_id', $shipmentId)
                ->where('type', 'master')->orderByDesc('created_at')->first(['demurrage_free_days', 'detention_free_days']);

        return [
            'demurrage' => $row?->demurrage_free_days === null ? null : (int) $row->demurrage_free_days,
            'detention' => $row?->detention_free_days === null ? null : (int) $row->detention_free_days,
        ];
    }

    /** Colonne ouvrant un compteur, par sens. */
    public static function startColumn(string $kind, string $direction): string
    {
        return self::MILESTONE_COLUMNS[self::METER[$kind][$direction === 'export' ? 'export' : 'import']['start']];
    }

    /**
     * Colonne fermant un compteur, par sens. Tant qu'elle est nulle, le compteur
     * court encore.
     */
    public static function stopColumn(string $kind, string $direction): string
    {
        return self::MILESTONE_COLUMNS[self::METER[$kind][$direction === 'export' ? 'export' : 'import']['stop']];
    }

    /** Recalcule tout un dossier — appelé quand une franchise change. */
    public function refreshShipment(string $shipmentId): int
    {
        $ids = DB::table('container_assignments')->where('shipment_id', $shipmentId)->pluck('id');
        foreach ($ids as $id) {
            $this->refreshDeadlines((string) $id);
        }

        return $ids->count();
    }
}
