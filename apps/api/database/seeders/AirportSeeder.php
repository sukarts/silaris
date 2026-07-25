<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Aéroports — principaux hubs cargo mondiaux + Afrique de l'Ouest.
 */
class AirportSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $rows = [
            // Afrique
            ['ABJ', 'DIAP', 'Abidjan Félix-Houphouët-Boigny', 'CI'], ['DKR', 'GOBD', 'Dakar Blaise-Diagne', 'SN'],
            ['ACC', 'DGAA', 'Accra Kotoka', 'GH'], ['LOS', 'DNMM', 'Lagos Murtala-Muhammed', 'NG'],
            ['ABV', 'DNAA', 'Abuja', 'NG'], ['COO', 'DBBB', 'Cotonou Cadjehoun', 'BJ'],
            ['LFW', 'DXXX', 'Lomé Gnassingbé-Eyadéma', 'TG'], ['OUA', 'DFFD', 'Ouagadougou', 'BF'],
            ['BKO', 'GABS', 'Bamako Sénou', 'ML'], ['NIM', 'DRRN', 'Niamey', 'NE'],
            ['CKY', 'GUCY', 'Conakry', 'GN'], ['DLA', 'FKKD', 'Douala', 'CM'],
            ['NSI', 'FKYS', 'Yaoundé Nsimalen', 'CM'], ['LBV', 'FOOL', 'Libreville', 'GA'],
            ['CMN', 'GMMN', 'Casablanca Mohammed-V', 'MA'], ['ALG', 'DAAG', 'Alger Houari-Boumédiène', 'DZ'],
            ['TUN', 'DTTA', 'Tunis-Carthage', 'TN'], ['CAI', 'HECA', 'Le Caire', 'EG'],
            ['ADD', 'HAAB', 'Addis-Abeba Bole', 'ET'], ['NBO', 'HKJK', 'Nairobi Jomo-Kenyatta', 'KE'],
            ['JNB', 'FAOR', 'Johannesburg O.R.-Tambo', 'ZA'],
            // Europe
            ['CDG', 'LFPG', 'Paris Charles-de-Gaulle', 'FR'], ['ORY', 'LFPO', 'Paris Orly', 'FR'],
            ['AMS', 'EHAM', 'Amsterdam Schiphol', 'NL'], ['FRA', 'EDDF', 'Francfort', 'DE'],
            ['LGG', 'EBLG', 'Liège', 'BE'], ['BRU', 'EBBR', 'Bruxelles', 'BE'],
            ['LHR', 'EGLL', 'Londres Heathrow', 'GB'], ['MAD', 'LEMD', 'Madrid Barajas', 'ES'],
            ['MXP', 'LIMC', 'Milan Malpensa', 'IT'], ['LUX', 'ELLX', 'Luxembourg Findel', 'LU'],
            ['IST', 'LTFM', 'Istanbul', 'TR'], ['ZRH', 'LSZH', 'Zurich', 'CH'],
            // Moyen-Orient & Asie
            ['DXB', 'OMDB', 'Dubaï International', 'AE'], ['DWC', 'OMDW', 'Dubaï Al-Maktoum', 'AE'],
            ['AUH', 'OMAA', 'Abou Dabi', 'AE'], ['DOH', 'OTHH', 'Doha Hamad', 'QA'],
            ['JED', 'OEJN', 'Djeddah', 'SA'], ['RUH', 'OERK', 'Riyad', 'SA'],
            ['HKG', 'VHHH', 'Hong Kong', 'HK'], ['PVG', 'ZSPD', 'Shanghai Pudong', 'CN'],
            ['CAN', 'ZGGG', 'Canton Baiyun', 'CN'], ['PEK', 'ZBAA', 'Pékin Capitale', 'CN'],
            ['SZX', 'ZGSZ', 'Shenzhen', 'CN'], ['ICN', 'RKSI', 'Séoul Incheon', 'KR'],
            ['NRT', 'RJAA', 'Tokyo Narita', 'JP'], ['SIN', 'WSSS', 'Singapour Changi', 'SG'],
            ['BKK', 'VTBS', 'Bangkok Suvarnabhumi', 'TH'], ['BOM', 'VABB', 'Mumbai', 'IN'],
            ['DEL', 'VIDP', 'Delhi Indira-Gandhi', 'IN'], ['CMB', 'VCBI', 'Colombo', 'LK'],
            // Amériques
            ['JFK', 'KJFK', 'New York JFK', 'US'], ['MIA', 'KMIA', 'Miami', 'US'],
            ['ORD', 'KORD', 'Chicago O\'Hare', 'US'], ['LAX', 'KLAX', 'Los Angeles', 'US'],
            ['ATL', 'KATL', 'Atlanta', 'US'], ['YYZ', 'CYYZ', 'Toronto Pearson', 'CA'],
            ['YUL', 'CYUL', 'Montréal Trudeau', 'CA'], ['MEX', 'MMMX', 'Mexico', 'MX'],
            ['GRU', 'SBGR', 'São Paulo Guarulhos', 'BR'], ['BOG', 'SKBO', 'Bogota El Dorado', 'CO'],
        ];

        // LU absent du CountrySeeder — l'ajouter à la volée pour LUX.
        DB::table('countries')->upsert(
            [['code2' => 'LU', 'code3' => 'LUX', 'name_fr' => 'Luxembourg', 'name_en' => 'Luxembourg', 'created_at' => $now, 'updated_at' => $now]],
            ['code2'], ['code3', 'name_fr', 'name_en', 'updated_at'],
        );

        DB::table('airports')->upsert(
            array_map(fn (array $r) => [
                'iata' => $r[0], 'icao' => $r[1], 'name' => $r[2], 'country_code' => $r[3],
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ], $rows),
            ['iata'],
            ['icao', 'name', 'country_code', 'updated_at'],
        );
    }
}
