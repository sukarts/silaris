<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue des prestations et débours du transit.
 *
 * Les postes d'une cotation ou d'une facture se répétaient d'un dossier à
 * l'autre, saisis à la main, avec autant d'orthographes que d'exploitants —
 * « acconage », « aconage », « ACCONAGE ». Un même poste facturé sous deux
 * libellés ne se recoupe pas d'un dossier à l'autre, ni d'un tableau de bord.
 *
 * D'où ce référentiel : le vocabulaire commun du métier, proposé à la saisie.
 * Il ne l'impose pas — une ligne libre reste possible pour un cas non prévu.
 *
 * Deux familles, celles des sous-totaux de la facture : ce qui part à la douane,
 * et le reste — débours et prestations du transitaire confondus. Un périmètre
 * distingue à part les postes propres à l'import de véhicules (immatriculation),
 * pour ne pas noyer la liste courante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_catalog', function (Blueprint $table): void {
            $table->string('code', 32)->primary();
            $table->string('label', 120);
            $table->string('family', 16)->comment('customs = débours douane, other = débours divers & prestations');
            $table->string('scope', 16)->default('general')->comment('general ou vehicle (immatriculation)');
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE service_catalog ADD CONSTRAINT ck_service_catalog_family CHECK (family IN ('customs', 'other'))");
        DB::statement("ALTER TABLE service_catalog ADD CONSTRAINT ck_service_catalog_scope CHECK (scope IN ('general', 'vehicle'))");

        // Débours douane — ce qui part à la douane.
        $customs = [
            'DROITS_TAXES' => 'Droit et taxes de douane',
            'DUS' => 'Droit Unique de Sortie (DUS)',
            'TS_DOUANE' => 'TS douane',
            'AMENDE_DOUANE' => 'Amende douane',
        ];

        // Débours divers & prestations — dans l'ordre d'un dossier import.
        $other = [
            'OUVERTURE' => 'Ouverture de dossier',
            'FDI_RFCV' => 'FDI/RFCV',
            'DEBLOCAGE_LIGNE' => 'Déblocage de ligne',
            'ASSURANCE' => 'Assurance',
            'CAUTION_CONT' => 'Caution conteneur',
            'ECHANGE_BL' => 'Echange BL',
            'AUTO_D25' => 'Autorisation D25',
            'PASSAGE_GUCE' => 'Passage guichet unique',
            'OUV_GUICHET' => 'Ouverture guichet',
            'CAPITAINERIE' => 'Capitainerie',
            'ACCORD_VN' => 'Accord VN / CIVIO',
            'CHGT_CIRCUIT' => 'Changement circuit',
            'CHGT_NAVIRE' => 'Changement de navire',
            'ACCONAGE' => 'Acconage',
            'MAGASINAGE' => 'Magasinage',
            'STOCKAGE_PARC' => 'Stockage parc',
            'STATIONNEMENT' => 'Stationnement',
            'SURESTARIES' => 'Surestaries',
            'DETENTION' => 'Détention de conteneur',
            'IMMO_CAMION' => 'Immobilisation de camion',
            'MANUTENTION' => 'Manutention',
            'VGM' => 'Pesée conteneur (VGM)',
            'VISITE_EMPOTAGE' => "Visite + rapport d'empotage douane",
            'PASSAGE_DOUANE' => 'Passage douane',
            'ESCORTE' => 'Escorte douane',
            'PHYTO' => 'Certificat phytosanitaire',
            'EIR_CAMION' => 'EIR + Mise sur camion',
            'POSITIONNEMENT' => 'Positionnement',
            'RELEVAGE_PARC' => 'Relevage parc',
            'RELEVAGE_TERM' => 'Relevage terminal',
            'TRANSFERT_PARC_TERM' => 'Transfert parc - terminal',
            'REEFER' => 'Branchement reefer',
            'GENSET' => 'Location genset',
            'BALISE' => 'Balise',
            'RECTIF_PLOMB' => 'Rectif plomb',
            'FOND_GARANTIE' => 'Fond de garantie',
            'CHARGES_LOCALES' => 'Charges locales',
            'AGIOS' => 'Agios',
            'AMENDE_BSC' => 'Amende BSC',
            'TIRAGE' => 'Tirage',
            'LIVRAISON' => 'Livraison',
            'COMMISSION' => 'Commission de facilitation',
            'PRESTATION' => 'Prestation',
        ];

        // Import de véhicules — étapes d'immatriculation, périmètre à part.
        $vehicle = [
            'RECEPTION_PHYS' => 'Réception physique',
            'VISITE_TECH' => 'Visite technique',
            'PHOTO_AERIA' => 'Photo aéria',
            'RTI' => 'RTI',
            'ACCORD_CIVIO' => 'Accord VN / CIVIO (véhicule)',
            'CARTE_GRISE' => 'Carte grise',
            'CIL_VIGNETTE' => 'CIL vignette',
            'POSE_PLAQUE' => 'Pose plaque',
            'MUTATION' => 'Mutation',
        ];

        $now = now();
        $rows = [];
        $position = 0;
        foreach ([['customs', 'general', $customs], ['other', 'general', $other], ['other', 'vehicle', $vehicle]] as [$family, $scope, $set]) {
            foreach ($set as $code => $label) {
                $rows[] = [
                    'code' => $code, 'label' => $label, 'family' => $family, 'scope' => $scope,
                    'position' => $position += 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }
        DB::table('service_catalog')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('service_catalog');
    }
};
