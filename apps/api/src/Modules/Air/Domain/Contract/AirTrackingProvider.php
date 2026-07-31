<?php

declare(strict_types=1);

namespace Silaris\Modules\Air\Domain\Contract;

/**
 * Port du suivi aérien — un fournisseur (ShipsGo aujourd'hui) le remplit.
 *
 * Volontairement distinct du suivi maritime : l'aérien parle aéroports (IATA),
 * vols et jalons Cargo-iMP, là où le maritime parle ports (LOCODE), navires et
 * jalons DCSA. Mélanger les deux forcerait chaque domaine à porter le
 * vocabulaire de l'autre.
 */
interface AirTrackingProvider
{
    /**
     * Suit une LTA par son numéro. Le préfixe compagnie aide le fournisseur à
     * lever l'ambiguïté quand plusieurs compagnies partagent une série.
     */
    public function trackByAwb(string $awbNumber, ?string $airlinePrefix = null): AirTrackingResult;
}
