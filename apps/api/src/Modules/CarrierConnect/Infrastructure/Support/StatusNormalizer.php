<?php

declare(strict_types=1);

namespace Silaris\Modules\CarrierConnect\Infrastructure\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Statut propriétaire → code DCSA via carrier_status_mappings (seedés Étape 8,
 * enrichis en exploitation). Statut inconnu → conservé brut avec code UNKN
 * (visible en supervision, mapping ajouté ensuite sans perte de donnée).
 */
final class StatusNormalizer
{
    public function normalize(string $scac, string $rawStatus): string
    {
        $mappings = Cache::remember(
            "carrier_status_map:{$scac}",
            600,
            fn () => DB::table('carrier_status_mappings')->where('carrier_scac', $scac)->pluck('dcsa_event_code', 'raw_status')->all(),
        );

        return $mappings[$rawStatus] ?? 'UNKN';
    }
}
