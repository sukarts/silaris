<?php

declare(strict_types=1);

namespace Silaris\Modules\Tracking\Application\Service;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Abonnement au suivi transporteur.
 *
 * Le suivi n'a rien à interroger tant qu'aucun numéro ne lui est confié. Le
 * moment naturel est l'affectation : dès qu'un conteneur rejoint un dossier, ou
 * qu'un connaissement est enregistré, le numéro existe et le suivi peut partir.
 *
 * L'abonnement est idempotent — réaffecter un conteneur au même dossier ne
 * crée pas de doublon, et réactive un abonnement suspendu.
 */
final class TrackingSubscriber
{
    /**
     * @param  'container'|'bl'|'awb'  $subjectType
     * @return string|null Identifiant de l'abonnement, null si le numéro est vide.
     */
    public function subscribe(
        string $tenantId,
        string $shipmentId,
        string $subjectType,
        ?string $subjectNumber,
        ?string $carrierScac = null,
    ): ?string {
        $number = strtoupper(str_replace([' ', '-'], '', (string) $subjectNumber));
        if ($number === '') {
            return null;
        }

        $existing = DB::table('tracking_subscriptions')
            ->where('tenant_id', $tenantId)
            ->where('subject_type', $subjectType)
            ->where('subject_number', $number)
            ->where('shipment_id', $shipmentId)
            ->first(['id', 'status']);

        if ($existing !== null) {
            // Un dossier rouvert reprend son suivi là où il s'était arrêté.
            if ($existing->status !== 'active') {
                DB::table('tracking_subscriptions')->where('id', $existing->id)
                    ->update(['status' => 'active', 'consecutive_failures' => 0, 'updated_at' => now()]);
            }

            return (string) $existing->id;
        }

        $id = (string) Str::uuid7();
        DB::table('tracking_subscriptions')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'subject_type' => $subjectType,
            'subject_number' => $number,
            'shipment_id' => $shipmentId,
            'carrier_scac' => $carrierScac,
            'status' => 'active',
            'consecutive_failures' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
