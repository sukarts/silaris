<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Tests d'architecture — les règles de l'Étape 2 (§4-5) sont EXÉCUTABLES.
| Toute violation des frontières Clean Architecture casse le build.
|--------------------------------------------------------------------------
*/

// 1. La couche Domain ignore le framework : ni Laravel, ni Eloquent, ni HTTP.
arch('le Domain ne dépend jamais du framework')
    ->expect('Silaris\Modules\Shipment\Domain')
    ->not->toUse(['Illuminate', 'Laravel', 'Symfony\Component\HttpFoundation'])
    ->and('Silaris\Modules\Pricing\Domain')
    ->not->toUse(['Illuminate', 'Laravel'])
    ->and('Silaris\Modules\Tracking\Domain')
    ->not->toUse(['Illuminate', 'Laravel'])
    ->and('Silaris\Modules\Shared\Domain')
    ->not->toUse(['Illuminate', 'Laravel']);

// 2. Le Domain ne touche jamais aux modèles Eloquent d'aucun module.
arch('le Domain n utilise aucun modèle Eloquent')
    ->expect('Silaris\Modules\Shipment\Domain')
    ->not->toUse('Silaris\Modules\Shipment\Infrastructure')
    ->and('Silaris\Modules\Pricing\Domain')
    ->not->toUse('Silaris\Modules\Pricing\Infrastructure');

// 3. Cloisonnement inter-modules : un Domain ne dépend jamais du Domain
//    d'un autre module métier (communication par événements/contrats).
arch('les Domains métier sont étanches entre eux')
    ->expect('Silaris\Modules\Shipment\Domain')
    ->not->toUse(['Silaris\Modules\Ocean', 'Silaris\Modules\Billing', 'Silaris\Modules\Crm', 'Silaris\Modules\Pricing'])
    ->and('Silaris\Modules\Pricing\Domain')
    ->not->toUse(['Silaris\Modules\Shipment', 'Silaris\Modules\Billing', 'Silaris\Modules\Crm']);

// 4. Les ACL (OdooSync, CarrierConnect) ne fuient jamais dans les Domains.
arch('les ACL ne contaminent pas le domaine')
    ->expect('Silaris\Modules\Shipment\Domain')
    ->not->toUse(['Silaris\Modules\OdooSync', 'Silaris\Modules\CarrierConnect'])
    ->and('Silaris\Modules\Tracking\Domain')
    ->not->toUse(['Silaris\Modules\OdooSync', 'Silaris\Modules\CarrierConnect']);

// 5. Hygiène globale.
arch('strict_types partout dans les modules')
    ->expect('Silaris\Modules')
    ->toUseStrictTypes();

arch('pas de debug oublié')
    ->expect(['dd', 'dump', 'var_dump', 'ray'])
    ->not->toBeUsed();
