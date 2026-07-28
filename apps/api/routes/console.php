<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

// Worker outbox → notifications email (départ/arrivée/retard/facture…).
Schedule::command('outbox:process')->everyMinute()->withoutOverlapping();

// Tracking automatique — fréquence fine gérée par tenant (tracking_refresh_minutes).
Schedule::command('tracking:refresh')->everyThirtyMinutes()->withoutOverlapping();

// Partitions mensuelles préparées à l'avance.
Schedule::command('db:create-partitions')->monthlyOn(25, '02:00');

// Vue matérialisée CA opérationnel.
Schedule::call(fn () => DB::statement('REFRESH MATERIALIZED VIEW CONCURRENTLY mv_revenue_operational'))
    ->hourly()->name('refresh-revenue-view')->withoutOverlapping();

// Synchronisation Odoo : healthcheck + pull taxes et statuts de paiement.
Schedule::command('odoo:sync')->hourly()->withoutOverlapping();

// Franchises conteneur : l'alerte doit précéder la facturation de la compagnie,
// d'où un passage tôt le matin, avant l'ouverture des terminaux.
Schedule::command('demurrage:alert')->dailyAt('06:00');
