<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Silaris\Modules\Tracking\Application\Service\TrackingSubscriber;

/**
 * Rattrapage : jusqu'ici rien ne créait d'abonnement au suivi hors du jeu de
 * démonstration. Les conteneurs et connaissements déjà rattachés à un dossier
 * n'étaient donc jamais interrogés — le suivi restait vide sans explication.
 *
 * Cette migration abonne l'existant. Les dossiers clos sont écartés : rouvrir
 * leur suivi ferait repartir des appels transporteur sans objet.
 */
return new class extends Migration
{
    public function up(): void
    {
        $subscriber = app(TrackingSubscriber::class);

        $containers = DB::table('container_assignments')
            ->join('containers', 'containers.id', '=', 'container_assignments.container_id')
            ->join('shipments', 'shipments.id', '=', 'container_assignments.shipment_id')
            ->whereNull('shipments.closed_at')
            ->get(['shipments.tenant_id', 'shipments.id AS shipment_id', 'containers.number']);

        foreach ($containers as $row) {
            $this->withTenant((string) $row->tenant_id, fn () => $subscriber->subscribe(
                (string) $row->tenant_id, (string) $row->shipment_id, 'container', (string) $row->number,
            ));
        }

        $bills = DB::table('bills_of_lading')
            ->join('shipments', 'shipments.id', '=', 'bills_of_lading.shipment_id')
            ->where('bills_of_lading.type', 'master')
            ->whereNull('shipments.closed_at')
            ->get(['shipments.tenant_id', 'shipments.id AS shipment_id', 'bills_of_lading.number']);

        foreach ($bills as $row) {
            $this->withTenant((string) $row->tenant_id, fn () => $subscriber->subscribe(
                (string) $row->tenant_id, (string) $row->shipment_id, 'bl', (string) $row->number,
            ));
        }

        DB::statement("SELECT set_config('app.tenant_id', '', false)");
    }

    public function down(): void
    {
        // Les abonnements créés ici sont indiscernables de ceux nés d'une
        // affectation : les retirer couperait le suivi en cours.
    }

    /** Les policies RLS gouvernent l'écriture : le contexte doit désigner le tenant servi. */
    private function withTenant(string $tenantId, callable $work): void
    {
        DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]);
        $work();
    }
};
