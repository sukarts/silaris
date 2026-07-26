<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sous-traitance routière — peu de transitaires possèdent les camions qu'ils
 * font rouler : le pré/post-acheminement passe par des prestataires.
 *
 * Un camion, une remorque ou un chauffeur appartient donc soit à la flotte
 * propre (`carrier_party_id` nul), soit à un transporteur enregistré au CRM
 * comme fournisseur. La mission porte le prestataire retenu, ce qui permet de
 * savoir qui roule sans dupliquer les fiches à chaque affrètement.
 *
 * Cette information reste strictement interne : ni le portail client ni le
 * suivi public n'exposent le transporteur, le chauffeur ou le véhicule.
 */
return new class extends Migration
{
    /** Tables décrivant un moyen de transport rattachable à un prestataire. */
    private const RESOURCE_TABLES = ['trucks', 'trailers', 'drivers'];

    public function up(): void
    {
        foreach (self::RESOURCE_TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->uuid('carrier_party_id')->nullable()
                    ->comment('Prestataire propriétaire — nul pour la flotte propre');
                $blueprint->foreign('carrier_party_id')->references('id')->on('parties');
                $blueprint->index(['tenant_id', 'carrier_party_id'], "ix_{$table}_carrier");
            });
        }

        Schema::table('missions', function (Blueprint $table): void {
            $table->uuid('carrier_party_id')->nullable()->after('shipment_id')
                ->comment('Transporteur affrété — nul quand la mission est assurée en propre');
            $table->string('carrier_reference', 64)->nullable()->after('carrier_party_id')
                ->comment('N° d\'ordre de transport chez le prestataire');
            $table->foreign('carrier_party_id')->references('id')->on('parties');
            $table->index(['tenant_id', 'carrier_party_id'], 'ix_missions_carrier');
        });
    }

    public function down(): void
    {
        Schema::table('missions', function (Blueprint $table): void {
            $table->dropForeign(['carrier_party_id']);
            $table->dropIndex('ix_missions_carrier');
            $table->dropColumn(['carrier_party_id', 'carrier_reference']);
        });

        foreach (self::RESOURCE_TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropForeign(['carrier_party_id']);
                $blueprint->dropIndex("ix_{$table}_carrier");
                $blueprint->dropColumn('carrier_party_id');
            });
        }
    }
};
