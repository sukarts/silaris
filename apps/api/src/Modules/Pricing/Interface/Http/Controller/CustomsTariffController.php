<?php

declare(strict_types=1);

namespace Silaris\Modules\Pricing\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Silaris\Modules\Pricing\Domain\Service\CustomsDutyCalculator;

/**
 * Tarif douanier et calcul des débours douane.
 *
 * Chiffrer les droits à la main sur une offre import prend du temps et se
 * trompe : huit lignes, des assiettes différentes, une TVA qui frappe le tout.
 * Le tarif officiel donne le droit et la TVA de chaque position ; le reste se
 * déduit.
 */
class CustomsTariffController
{
    /**
     * Position exacte, sinon la première déclinaison de la sous-position.
     *
     * Le tarif ne contient pas toujours le code à dix chiffres qu'on lui
     * présente : une nomenclature s'arrête où la douane l'a subdivisée. On
     * remonte donc de deux chiffres en deux chiffres jusqu'à la sous-position
     * à six, au-delà de laquelle les taux cesseraient d'être comparables.
     */
    private static function findTariff(string $code): ?object
    {
        for ($length = strlen($code); $length >= 6; $length -= 2) {
            $prefix = substr($code, 0, $length);
            $tariff = DB::table('customs_tariffs')
                ->where('hs_code', 'like', $prefix.'%')
                ->orderBy('hs_code')
                ->first();

            if ($tariff !== null) {
                return $tariff;
            }
        }

        return null;
    }

    /** GET /v1/customs-tariffs?search= — recherche par position ou libellé. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['sometimes', 'string', 'max:120'],
        ]);
        $search = trim($data['search'] ?? '');

        $rows = DB::table('customs_tariffs')
            ->when($search !== '', function ($query) use ($search) {
                $digits = preg_replace('/\D/', '', $search) ?? '';

                // Une position se cherche par ses chiffres, une marchandise par
                // son libellé : l'exploitant connaît l'un ou l'autre.
                return $digits !== ''
                    ? $query->where('hs_code', 'like', $digits.'%')
                    : $query->whereRaw('LOWER(description) LIKE ?', ['%'.mb_strtolower($search).'%']);
            })
            ->orderBy('hs_code')
            ->limit(30)
            ->get(['hs_code', 'description', 'duty_rate', 'vat_rate', 'all_in_rate']);

        return response()->json(['data' => $rows]);
    }

    /** GET /v1/customs-regimes — régimes déclarables et leur effet sur les droits. */
    public function regimes(): JsonResponse
    {
        return response()->json([
            'data' => DB::table('customs_regimes')->orderBy('code')->get(),
        ]);
    }

    /**
     * POST /v1/customs-tariffs/compute — droits et taxes d'une valeur CAF.
     * Rend les montants ligne à ligne, prêts à garnir les débours douane.
     */
    public function compute(Request $request): JsonResponse
    {
        $data = $request->validate([
            // L'exploitant saisit souvent la position pointée (8703.23.00.00),
            // plus longue que le code brut.
            'hs_code' => ['required', 'string', 'max:20'],
            'customs_value' => ['required', 'numeric', 'min:0'],
            'customs_regime' => ['sometimes', 'nullable', 'string', 'exists:customs_regimes,code'],
        ]);

        $code = preg_replace('/\D/', '', $data['hs_code']) ?? '';
        $tariff = self::findTariff($code);

        if ($tariff === null) {
            return response()->json([
                'message' => "Position tarifaire {$data['hs_code']} introuvable au tarif douanier.",
            ], 422);
        }

        // Le tarif dit ce que la marchandise coûterait ; le régime dit si elle
        // le subit — un transit vers le Mali ne paie aucun droit ivoirien.
        $regime = isset($data['customs_regime'])
            ? DB::table('customs_regimes')->where('code', $data['customs_regime'])->first()
            : null;

        $computed = CustomsDutyCalculator::compute(
            (float) $data['customs_value'],
            (float) $tariff->duty_rate,
            (float) $tariff->vat_rate,
            $regime === null ? null : [
                'duty_applies' => (bool) $regime->duty_applies,
                'vat_applies' => (bool) $regime->vat_applies,
                'levies_apply' => (bool) $regime->levies_apply,
            ],
        );

        return response()->json([
            'hs_code' => $tariff->hs_code,
            'requested_hs_code' => $data['hs_code'],
            'description' => $tariff->description,
            'duty_rate' => (float) $tariff->duty_rate,
            'vat_rate' => (float) $tariff->vat_rate,
            'customs_value' => (float) $data['customs_value'],
            'customs_regime' => $regime?->code,
            'regime_name' => $regime?->name,
            'regime_note' => $regime?->note,
            ...$computed,
        ]);
    }
}
