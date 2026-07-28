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
 *
 * La compagnie doit être connue pour interroger le suivi : l'agrégateur la
 * réclame en paramètre. Elle est cherchée dans le booking du dossier, source
 * autorisée, puis à défaut déduite du préfixe propriétaire du conteneur —
 * MSCU appartient à MSC. Faute des deux, l'abonnement attend le booking.
 */
final class TrackingSubscriber
{
    /**
     * Complète la compagnie des abonnements d'un dossier dès qu'elle devient
     * connue — typiquement à la confirmation du booking, souvent postérieure à
     * l'affectation des conteneurs.
     *
     * @return int Nombre d'abonnements complétés.
     */
    public function attachCarrier(string $shipmentId, ?string $carrierScac): int
    {
        if ($carrierScac === null || $carrierScac === '') {
            return 0;
        }

        return DB::table('tracking_subscriptions')
            ->where('shipment_id', $shipmentId)
            ->whereNull('carrier_scac')
            ->update(['carrier_scac' => $carrierScac, 'updated_at' => now()]);
    }

    /** Compagnie du booking du dossier, sinon propriétaire du conteneur. */
    private function resolveCarrier(string $shipmentId, string $number): ?string
    {
        $fromBooking = DB::table('bookings')
            ->join('carriers', 'carriers.id', '=', 'bookings.carrier_id')
            ->where('bookings.shipment_id', $shipmentId)
            ->orderByDesc('bookings.created_at')
            ->value('carriers.scac');

        if ($fromBooking !== null) {
            return (string) $fromBooking;
        }

        // Préfixe propriétaire ISO 6346 : les armateurs immatriculent leurs
        // boîtes sous leur propre code (MSCU, MAEU…). Sans correspondance au
        // référentiel, on préfère ne rien affirmer.
        $prefix = substr($number, 0, 4);

        return DB::table('carriers')->where('scac', $prefix)->where('is_active', true)->value('scac');
    }

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
            ->first(['id', 'status', 'carrier_scac']);

        if ($existing !== null) {
            $changes = [];

            // Un dossier rouvert reprend son suivi là où il s'était arrêté.
            if ($existing->status !== 'active') {
                $changes = ['status' => 'active', 'consecutive_failures' => 0];
            }

            // Un abonnement né avant que la compagnie soit connue reste muet
            // tant qu'on ne la lui donne pas : la renseigner le débloque.
            if ($existing->carrier_scac === null) {
                $carrier = $carrierScac ?? $this->resolveCarrier($shipmentId, $number);
                if ($carrier !== null) {
                    $changes['carrier_scac'] = $carrier;
                }
            }

            if ($changes !== []) {
                DB::table('tracking_subscriptions')->where('id', $existing->id)
                    ->update([...$changes, 'updated_at' => now()]);
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
            'carrier_scac' => $carrierScac ?? $this->resolveCarrier($shipmentId, $number),
            'status' => 'active',
            'consecutive_failures' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
