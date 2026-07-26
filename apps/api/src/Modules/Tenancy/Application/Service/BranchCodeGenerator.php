<?php

declare(strict_types=1);

namespace Silaris\Modules\Tenancy\Application\Service;

use Illuminate\Support\Facades\DB;

/**
 * Code agence normalisé sur l'UN/LOCODE, standard du transit : deux lettres de
 * pays suivies de trois lettres de ville (CIABJ = Abidjan, FRMRS = Marseille).
 * Les codes restent ainsi lisibles par n'importe quel correspondant, ici comme
 * à l'étranger.
 *
 * Le LOCODE officiel est repris quand la ville figure au référentiel ; sinon
 * il est dérivé du nom. En cas de collision dans le tenant, un rang numérique
 * est ajouté (CIABJ, CIABJ2…) plutôt que de renvoyer une erreur.
 */
final class BranchCodeGenerator
{
    public function generate(string $countryCode, string $city, ?string $locode = null): string
    {
        $base = strtoupper((string) ($locode ?: $this->officialLocode($countryCode, $city)))
            ?: strtoupper(substr($countryCode, 0, 2)).self::cityToken($city);

        return $this->makeUnique(substr($base, 0, 8));
    }

    /**
     * LOCODE officiel de la ville quand elle figure au référentiel : on préfère
     * toujours le code réel (CIABJ) à une dérivation approchée (CIABD).
     */
    private function officialLocode(string $countryCode, string $city): ?string
    {
        $city = trim($city);

        return DB::table('ports')
            ->where('country_code', strtoupper(substr($countryCode, 0, 2)))
            ->where('is_active', true)
            // Les exonymes diffèrent d'une langue à l'autre (Antwerpen / Antwerp) :
            // un préfixe commun suffit à retrouver le LOCODE officiel.
            ->where(fn ($query) => $query
                ->whereRaw('LOWER(name) = LOWER(?)', [$city])
                ->orWhereRaw('LOWER(?) LIKE LOWER(name) || \'%\'', [$city])
                ->orWhereRaw('LOWER(name) LIKE LOWER(?) || \'%\'', [$city]))
            ->orderByRaw('LENGTH(name)')
            ->value('locode');
    }

    /**
     * Trois lettres depuis le nom de ville : consonnes d'abord (usage LOCODE
     * — Abidjan → ABJ, Marseille → MRS), complétées si nécessaire.
     */
    private static function cityToken(string $city): string
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper(self::withoutAccents($city))) ?? '';
        if ($letters === '') {
            return 'XXX';
        }

        $consonants = preg_replace('/[AEIOUY]/', '', substr($letters, 1)) ?? '';
        $token = substr($letters, 0, 1).$consonants;

        return str_pad(substr($token.$letters, 0, 3), 3, 'X');
    }

    private static function withoutAccents(string $value): string
    {
        return strtr($value, [
            'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Á' => 'A', 'Ã' => 'A', 'Å' => 'A',
            'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Õ' => 'O',
            'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ç' => 'C', 'Ñ' => 'N',
        ]);
    }

    private function makeUnique(string $base): string
    {
        $existing = DB::table('branches')->where('code', 'like', $base.'%')->pluck('code')->all();
        if (! in_array($base, $existing, true)) {
            return $base;
        }

        for ($rank = 2; $rank < 100; $rank++) {
            $candidate = substr($base, 0, 8 - strlen((string) $rank)).$rank;
            if (! in_array($candidate, $existing, true)) {
                return $candidate;
            }
        }

        return substr($base, 0, 4).random_int(1000, 9999);
    }
}
