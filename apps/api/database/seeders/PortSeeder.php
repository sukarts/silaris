<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Ports — principaux ports mondiaux + couverture Afrique de l'Ouest (UN/LOCODE).
 */
class PortSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $rows = [
            // Afrique de l'Ouest & Centrale
            ['CIABJ', 'Abidjan', 'CI'], ['CISPY', 'San-Pédro', 'CI'],
            ['SNDKR', 'Dakar', 'SN'], ['GHTEM', 'Tema', 'GH'], ['GHTKD', 'Takoradi', 'GH'],
            ['NGLOS', 'Lagos (Apapa)', 'NG'], ['NGTIN', 'Tin Can Island', 'NG'], ['NGONN', 'Onne', 'NG'],
            ['BJCOO', 'Cotonou', 'BJ'], ['TGLFW', 'Lomé', 'TG'], ['GNCKY', 'Conakry', 'GN'],
            ['LRMLW', 'Monrovia', 'LR'], ['SLFNA', 'Freetown', 'SL'], ['MRNKC', 'Nouakchott', 'MR'],
            ['GMBJL', 'Banjul', 'GM'], ['CMDLA', 'Douala', 'CM'], ['CMKBI', 'Kribi', 'CM'],
            ['GALBV', 'Libreville (Owendo)', 'GA'], ['CGPNR', 'Pointe-Noire', 'CG'], ['CDMAT', 'Matadi', 'CD'],
            // Afrique Nord / Est / Australe
            ['MAPTM', 'Tanger Med', 'MA'], ['MACAS', 'Casablanca', 'MA'],
            ['DZALG', 'Alger', 'DZ'], ['TNRAD', 'Radès', 'TN'], ['LYTIP', 'Tripoli', 'LY'],
            ['EGALY', 'Alexandrie', 'EG'], ['EGPSD', 'Port-Saïd', 'EG'], ['EGSUZ', 'Suez', 'EG'],
            ['DJJIB', 'Djibouti', 'DJ'], ['KEMBA', 'Mombasa', 'KE'], ['TZDAR', 'Dar es Salam', 'TZ'],
            ['ZADUR', 'Durban', 'ZA'], ['ZACPT', 'Le Cap', 'ZA'], ['ZAPLZ', 'Gqeberha', 'ZA'],
            ['AOLAD', 'Luanda', 'AO'], ['MZMPM', 'Maputo', 'MZ'], ['MZBEW', 'Beira', 'MZ'],
            // Europe
            ['FRLEH', 'Le Havre', 'FR'], ['FRMRS', 'Marseille-Fos', 'FR'], ['FRDKK', 'Dunkerque', 'FR'],
            ['NLRTM', 'Rotterdam', 'NL'], ['BEANR', 'Anvers', 'BE'], ['BEZEE', 'Zeebruges', 'BE'],
            ['DEHAM', 'Hambourg', 'DE'], ['DEBRV', 'Bremerhaven', 'DE'],
            ['ESALG', 'Algésiras', 'ES'], ['ESVLC', 'Valence', 'ES'], ['ESBCN', 'Barcelone', 'ES'],
            ['PTLIS', 'Lisbonne', 'PT'], ['PTSIE', 'Sines', 'PT'],
            ['ITGOA', 'Gênes', 'IT'], ['ITSPE', 'La Spezia', 'IT'], ['ITGIT', 'Gioia Tauro', 'IT'],
            ['GBFXT', 'Felixstowe', 'GB'], ['GBSOU', 'Southampton', 'GB'], ['GBLGP', 'London Gateway', 'GB'],
            ['GRPIR', 'Le Pirée', 'GR'], ['MTMAR', 'Marsaxlokk', 'MT'], ['TRAMR', 'Ambarli', 'TR'],
            ['TRMER', 'Mersin', 'TR'], ['PLGDN', 'Gdansk', 'PL'], ['SEGOT', 'Göteborg', 'SE'],
            // Asie & Moyen-Orient
            ['CNSHA', 'Shanghai', 'CN'], ['CNNGB', 'Ningbo', 'CN'], ['CNSZX', 'Shenzhen', 'CN'],
            ['CNCAN', 'Canton (Nansha)', 'CN'], ['CNTAO', 'Qingdao', 'CN'], ['CNTXG', 'Tianjin', 'CN'],
            ['CNXMN', 'Xiamen', 'CN'], ['CNDLC', 'Dalian', 'CN'],
            ['HKHKG', 'Hong Kong', 'HK'], ['TWKHH', 'Kaohsiung', 'TW'],
            ['SGSIN', 'Singapour', 'SG'], ['MYPKG', 'Port Klang', 'MY'], ['MYTPP', 'Tanjung Pelepas', 'MY'],
            ['THLCH', 'Laem Chabang', 'TH'], ['VNSGN', 'Hô Chi Minh-Ville', 'VN'], ['VNHPH', 'Haïphong', 'VN'],
            ['IDJKT', 'Jakarta (Tanjung Priok)', 'ID'], ['PHMNL', 'Manille', 'PH'],
            ['JPYOK', 'Yokohama', 'JP'], ['JPTYO', 'Tokyo', 'JP'], ['JPUKB', 'Kobe', 'JP'],
            ['KRPUS', 'Busan', 'KR'], ['KRINC', 'Incheon', 'KR'],
            ['INNSA', 'Nhava Sheva (JNPT)', 'IN'], ['INMUN', 'Mundra', 'IN'], ['INMAA', 'Chennai', 'IN'],
            ['PKKHI', 'Karachi', 'PK'], ['BDCGP', 'Chittagong', 'BD'], ['LKCMB', 'Colombo', 'LK'],
            ['AEJEA', 'Jebel Ali (Dubaï)', 'AE'], ['AEAUH', 'Abou Dabi', 'AE'], ['AEKLF', 'Khor Fakkan', 'AE'],
            ['SAJED', 'Djeddah', 'SA'], ['SADMM', 'Dammam', 'SA'], ['QAHMD', 'Hamad', 'QA'],
            ['OMSLL', 'Salalah', 'OM'], ['OMSOH', 'Sohar', 'OM'], ['KWSWK', 'Shuwaikh', 'KW'],
            ['ILHFA', 'Haïfa', 'IL'], ['ILASD', 'Ashdod', 'IL'], ['LBBEY', 'Beyrouth', 'LB'],
            // Amériques
            ['USNYC', 'New York / New Jersey', 'US'], ['USLAX', 'Los Angeles', 'US'],
            ['USLGB', 'Long Beach', 'US'], ['USSAV', 'Savannah', 'US'], ['USHOU', 'Houston', 'US'],
            ['USORF', 'Norfolk', 'US'], ['USCHS', 'Charleston', 'US'], ['USOAK', 'Oakland', 'US'],
            ['USSEA', 'Seattle', 'US'], ['USMIA', 'Miami', 'US'],
            ['CAMTR', 'Montréal', 'CA'], ['CAVAN', 'Vancouver', 'CA'], ['CAHAL', 'Halifax', 'CA'],
            ['MXVER', 'Veracruz', 'MX'], ['MXZLO', 'Manzanillo', 'MX'],
            ['BRSSZ', 'Santos', 'BR'], ['BRRIG', 'Rio Grande', 'BR'], ['BRPNG', 'Paranaguá', 'BR'],
            ['ARBUE', 'Buenos Aires', 'AR'], ['CLVAP', 'Valparaíso', 'CL'], ['CLSAI', 'San Antonio', 'CL'],
            ['COCTG', 'Carthagène', 'CO'], ['PECLL', 'Callao', 'PE'],
            ['PAMIT', 'Manzanillo (Colón)', 'PA'], ['PABLB', 'Balboa', 'PA'],
            // Océanie
            ['AUSYD', 'Sydney', 'AU'], ['AUMEL', 'Melbourne', 'AU'], ['AUBNE', 'Brisbane', 'AU'],
            ['NZAKL', 'Auckland', 'NZ'],
        ];

        DB::table('ports')->upsert(
            array_map(fn (array $r) => [
                'locode' => $r[0], 'name' => $r[1], 'country_code' => $r[2],
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ], $rows),
            ['locode'],
            ['name', 'country_code', 'updated_at'],
        );
    }
}
