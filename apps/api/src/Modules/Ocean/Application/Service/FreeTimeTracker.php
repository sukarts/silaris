<?php

declare(strict_types=1);

namespace Silaris\Modules\Ocean\Application\Service;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Franchise et surestaries.
 *
 * La compagnie accorde un nombre de jours pendant lesquels l'immobilisation de
 * son conteneur n'est pas facturée. Passé ce délai, chaque jour coûte, et la
 * facture tombe sur le transitaire ou sur son client. Détecter l'échéance
 * avant qu'elle tombe vaut mieux que la constater après.
 *
 * Le compteur ne mesure pas la même chose selon le sens :
 *
 *  - à l'import, il part du déchargement du conteneur et s'arrête à la
 *    restitution du vide ;
 *  - à l'export, il part de la mise à disposition du vide et s'arrête à
 *    l'entrée du plein au terminal.
 *
 * Les jalons proviennent du suivi transporteur ; la franchise, elle, est
 * négociée par document — connaissement à l'import, booking à l'export.
 */
final readonly class FreeTimeTracker
{
    /** Événement DCSA ouvrant puis fermant le décompte, par sens de trafic. */
    private const METER = [
        'import' => ['start' => 'DISC', 'stop' => 'RETU'],
        'export' => ['start' => 'GTOT', 'stop' => 'GTIN'],
    ];

    /** Colonne d'horodatage de l'affectation, par code DCSA. */
    private const MILESTONE_COLUMNS = [
        'GTIN' => 'gate_in_at',
        'LOAD' => 'loaded_at',
        'DISC' => 'discharged_at',
        'GTOT' => 'gate_out_at',
        'RETU' => 'returned_at',
    ];

    /**
     * Reporte un jalon de suivi sur l'affectation du conteneur, puis recalcule
     * l'échéance. Sans ce report, la franchise resterait une donnée saisie que
     * rien ne confronte à la réalité du terrain.
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
            // Le premier passage fait foi : un relevé rejoué ne doit pas
            // repousser une échéance déjà courue.
            if ($assignment->current !== null) {
                continue;
            }

            DB::table('container_assignments')->where('id', $assignment->id)
                ->update([$column => $occurredAt, 'updated_at' => now()]);

            $this->refreshDeadline((string) $assignment->id);
        }
    }

    /** Recalcule `free_time_ends_at` d'une affectation depuis son document. */
    public function refreshDeadline(string $assignmentId): void
    {
        $row = DB::table('container_assignments')
            ->join('shipments', 'shipments.id', '=', 'container_assignments.shipment_id')
            ->where('container_assignments.id', $assignmentId)
            ->first([
                'container_assignments.id',
                'container_assignments.shipment_id',
                'container_assignments.discharged_at',
                'container_assignments.gate_out_at',
                'shipments.direction',
            ]);

        if ($row === null) {
            return;
        }

        $direction = $row->direction === 'export' ? 'export' : 'import';
        $days = $this->freeTimeDaysOf($row->shipment_id, $direction);
        $startedAt = $row->{self::startColumn($direction)};

        DB::table('container_assignments')->where('id', $row->id)->update([
            'free_time_days' => $days,
            'free_time_ends_at' => $days === null || $startedAt === null
                ? null
                : Carbon::parse($startedAt)->addDays($days),
            'updated_at' => now(),
        ]);
    }

    /**
     * Franchise du document porteur : le connaissement maître à l'import, le
     * booking à l'export — c'est là qu'elle se négocie.
     */
    public function freeTimeDaysOf(string $shipmentId, string $direction): ?int
    {
        $days = $direction === 'export'
            ? DB::table('bookings')->where('shipment_id', $shipmentId)
                ->orderByDesc('created_at')->value('free_time_days')
            : DB::table('bills_of_lading')->where('shipment_id', $shipmentId)
                ->where('type', 'master')->orderByDesc('created_at')->value('free_time_days');

        return $days === null ? null : (int) $days;
    }

    /** Colonne ouvrant le décompte : déchargement à l'import, sortie du vide à l'export. */
    public static function startColumn(string $direction): string
    {
        return self::MILESTONE_COLUMNS[self::METER[$direction === 'export' ? 'export' : 'import']['start']];
    }

    /**
     * Colonne fermant le décompte. Tant qu'elle est nulle, le conteneur est
     * encore chez le client et la franchise continue de courir.
     */
    public static function stopColumn(string $direction): string
    {
        return self::MILESTONE_COLUMNS[self::METER[$direction === 'export' ? 'export' : 'import']['stop']];
    }

    /** Recalcule tout un dossier — appelé quand la franchise du document change. */
    public function refreshShipment(string $shipmentId): int
    {
        $ids = DB::table('container_assignments')->where('shipment_id', $shipmentId)->pluck('id');
        foreach ($ids as $id) {
            $this->refreshDeadline((string) $id);
        }

        return $ids->count();
    }
}
