<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Pays — sous-ensemble couvrant les principaux corridors de commerce international.
 * Liste ISO 3166 complète importable ultérieurement (commande artisan referential:import).
 */
class CountrySeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $rows = [
            // Afrique de l'Ouest & Centrale
            ['CI', 'CIV', "Côte d'Ivoire", 'Ivory Coast'], ['SN', 'SEN', 'Sénégal', 'Senegal'],
            ['GH', 'GHA', 'Ghana', 'Ghana'], ['NG', 'NGA', 'Nigéria', 'Nigeria'],
            ['BJ', 'BEN', 'Bénin', 'Benin'], ['TG', 'TGO', 'Togo', 'Togo'],
            ['BF', 'BFA', 'Burkina Faso', 'Burkina Faso'], ['ML', 'MLI', 'Mali', 'Mali'],
            ['NE', 'NER', 'Niger', 'Niger'], ['GN', 'GIN', 'Guinée', 'Guinea'],
            ['LR', 'LBR', 'Libéria', 'Liberia'], ['SL', 'SLE', 'Sierra Leone', 'Sierra Leone'],
            ['MR', 'MRT', 'Mauritanie', 'Mauritania'], ['GM', 'GMB', 'Gambie', 'Gambia'],
            ['CM', 'CMR', 'Cameroun', 'Cameroon'], ['GA', 'GAB', 'Gabon', 'Gabon'],
            ['CG', 'COG', 'Congo', 'Congo'], ['CD', 'COD', 'RD Congo', 'DR Congo'],
            ['TD', 'TCD', 'Tchad', 'Chad'], ['CF', 'CAF', 'Centrafrique', 'Central African Rep.'],
            // Afrique Nord / Est / Australe
            ['MA', 'MAR', 'Maroc', 'Morocco'], ['DZ', 'DZA', 'Algérie', 'Algeria'],
            ['TN', 'TUN', 'Tunisie', 'Tunisia'], ['LY', 'LBY', 'Libye', 'Libya'],
            ['EG', 'EGY', 'Égypte', 'Egypt'], ['ET', 'ETH', 'Éthiopie', 'Ethiopia'],
            ['KE', 'KEN', 'Kenya', 'Kenya'], ['TZ', 'TZA', 'Tanzanie', 'Tanzania'],
            ['DJ', 'DJI', 'Djibouti', 'Djibouti'], ['ZA', 'ZAF', 'Afrique du Sud', 'South Africa'],
            ['AO', 'AGO', 'Angola', 'Angola'], ['MZ', 'MOZ', 'Mozambique', 'Mozambique'],
            // Europe
            ['FR', 'FRA', 'France', 'France'], ['DE', 'DEU', 'Allemagne', 'Germany'],
            ['NL', 'NLD', 'Pays-Bas', 'Netherlands'], ['BE', 'BEL', 'Belgique', 'Belgium'],
            ['ES', 'ESP', 'Espagne', 'Spain'], ['PT', 'PRT', 'Portugal', 'Portugal'],
            ['IT', 'ITA', 'Italie', 'Italy'], ['GB', 'GBR', 'Royaume-Uni', 'United Kingdom'],
            ['IE', 'IRL', 'Irlande', 'Ireland'], ['CH', 'CHE', 'Suisse', 'Switzerland'],
            ['PL', 'POL', 'Pologne', 'Poland'], ['GR', 'GRC', 'Grèce', 'Greece'],
            ['MT', 'MLT', 'Malte', 'Malta'], ['SE', 'SWE', 'Suède', 'Sweden'],
            ['NO', 'NOR', 'Norvège', 'Norway'], ['DK', 'DNK', 'Danemark', 'Denmark'],
            ['RO', 'ROU', 'Roumanie', 'Romania'], ['TR', 'TUR', 'Turquie', 'Turkey'],
            // Amériques
            ['US', 'USA', 'États-Unis', 'United States'], ['CA', 'CAN', 'Canada', 'Canada'],
            ['MX', 'MEX', 'Mexique', 'Mexico'], ['BR', 'BRA', 'Brésil', 'Brazil'],
            ['AR', 'ARG', 'Argentine', 'Argentina'], ['CL', 'CHL', 'Chili', 'Chile'],
            ['CO', 'COL', 'Colombie', 'Colombia'], ['PE', 'PER', 'Pérou', 'Peru'],
            ['PA', 'PAN', 'Panama', 'Panama'],
            // Asie & Moyen-Orient
            ['CN', 'CHN', 'Chine', 'China'], ['JP', 'JPN', 'Japon', 'Japan'],
            ['KR', 'KOR', 'Corée du Sud', 'South Korea'], ['TW', 'TWN', 'Taïwan', 'Taiwan'],
            ['HK', 'HKG', 'Hong Kong', 'Hong Kong'], ['SG', 'SGP', 'Singapour', 'Singapore'],
            ['MY', 'MYS', 'Malaisie', 'Malaysia'], ['TH', 'THA', 'Thaïlande', 'Thailand'],
            ['VN', 'VNM', 'Viêt Nam', 'Vietnam'], ['ID', 'IDN', 'Indonésie', 'Indonesia'],
            ['PH', 'PHL', 'Philippines', 'Philippines'], ['IN', 'IND', 'Inde', 'India'],
            ['PK', 'PAK', 'Pakistan', 'Pakistan'], ['BD', 'BGD', 'Bangladesh', 'Bangladesh'],
            ['LK', 'LKA', 'Sri Lanka', 'Sri Lanka'], ['AE', 'ARE', 'Émirats arabes unis', 'UAE'],
            ['SA', 'SAU', 'Arabie saoudite', 'Saudi Arabia'], ['QA', 'QAT', 'Qatar', 'Qatar'],
            ['OM', 'OMN', 'Oman', 'Oman'], ['KW', 'KWT', 'Koweït', 'Kuwait'],
            ['IL', 'ISR', 'Israël', 'Israel'], ['LB', 'LBN', 'Liban', 'Lebanon'],
            // Océanie
            ['AU', 'AUS', 'Australie', 'Australia'], ['NZ', 'NZL', 'Nouvelle-Zélande', 'New Zealand'],
        ];

        DB::table('countries')->upsert(
            array_map(fn (array $r) => [
                'code2' => $r[0], 'code3' => $r[1], 'name_fr' => $r[2], 'name_en' => $r[3],
                'created_at' => $now, 'updated_at' => $now,
            ], $rows),
            ['code2'],
            ['code3', 'name_fr', 'name_en', 'updated_at'],
        );
    }
}
