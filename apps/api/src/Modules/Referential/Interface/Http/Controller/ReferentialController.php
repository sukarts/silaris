<?php

declare(strict_types=1);

namespace Silaris\Modules\Referential\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Référentiels globaux — lecture seule, cache HTTP long. */
class ReferentialController
{
    private const TABLES = [
        'countries' => ['code2', 'code3', 'name_fr', 'name_en'],
        'currencies' => ['code', 'name', 'symbol', 'decimals'],
        'ports' => ['locode', 'name', 'country_code'],
        'airports' => ['iata', 'icao', 'name', 'country_code'],
        'incoterms' => ['code', 'label', 'version', 'cost_allocation'],
        'carriers' => ['id', 'scac', 'name', 'connector_key'],
        'airlines' => ['id', 'awb_prefix', 'iata', 'name'],
        'goods_types' => ['id', 'code', 'label_fr', 'label_en', 'imo_class', 'is_dangerous'],
    ];

    public function index(Request $request, string $referential): JsonResponse
    {
        abort_unless(isset(self::TABLES[$referential]), 404);

        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        $columns = self::TABLES[$referential];
        $searchable = array_slice($columns, 0, 3);

        $rows = DB::table($referential)
            ->select($columns)
            ->when(in_array($referential, ['ports', 'airports', 'carriers', 'airlines'], true),
                fn ($q) => $q->where('is_active', true))
            ->when($validated['search'] ?? null, function ($q, $s) use ($searchable): void {
                $q->where(function ($w) use ($searchable, $s): void {
                    foreach ($searchable as $col) {
                        $w->orWhereRaw("{$col}::text ILIKE ?", ["%{$s}%"]);
                    }
                });
            })
            ->orderBy($columns[0])
            ->paginate((int) ($validated['per_page'] ?? 100));

        return response()->json($rows)->header('Cache-Control', 'public, max-age=3600');
    }
}
