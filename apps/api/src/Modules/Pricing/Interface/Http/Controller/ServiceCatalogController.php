<?php

declare(strict_types=1);

namespace Silaris\Modules\Pricing\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Catalogue des prestations et débours, proposé à la saisie des lignes.
 *
 * Référentiel partagé et en lecture seule ici : il donne à la cotation comme à
 * la facture le vocabulaire commun du métier, sans interdire la ligne libre.
 */
class ServiceCatalogController
{
    /** GET /v1/service-catalog — postes actifs, ordonnés, par famille et périmètre. */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => DB::table('service_catalog')
                ->where('is_active', true)
                ->orderBy('position')
                ->get(['code', 'label', 'family', 'scope']),
        ]);
    }
}
