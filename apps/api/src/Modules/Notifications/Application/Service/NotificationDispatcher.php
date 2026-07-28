<?php

declare(strict_types=1);

namespace Silaris\Modules\Notifications\Application\Service;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Silaris\Modules\Notifications\Infrastructure\Mail\NotificationMail;
use Throwable;

/**
 * Crée et envoie une notification métier à un destinataire (compte portail ou
 * utilisateur interne) : résolution du template (tenant → défaut), préférences,
 * enregistrement notifications + notification_deliveries, envoi email.
 *
 * Le contexte tenant (app.tenant_id) doit être posé par l'appelant (worker outbox).
 */
final class NotificationDispatcher
{
    /** Templates par défaut (fr) quand le tenant n'en a pas défini. */
    private const DEFAULTS = [
        'departure' => ['Départ confirmé — dossier {{reference}}', "Votre expédition {{reference}} a quitté le port/l'aéroport d'origine.\nETA actuelle : {{eta}}."],
        'arrival' => ['Arrivée — dossier {{reference}}', "Votre expédition {{reference}} est arrivée à destination.\nNous vous tiendrons informé des étapes suivantes (douane, livraison)."],
        'customs' => ['Dédouanement en cours — dossier {{reference}}', "Le dédouanement de votre expédition {{reference}} a démarré.\nDes documents complémentaires pourront vous être demandés."],
        'delay' => ['Retard détecté — dossier {{reference}}', "Un retard d'environ {{delay_hours}} h a été détecté sur votre expédition {{reference}}.\nNouvelle ETA estimée : {{eta}}."],
        'delivery' => ['Livraison — dossier {{reference}}', 'Votre expédition {{reference}} est en cours de livraison.'],
        'demurrage_warning' => ['Franchise conteneur — dossier {{reference}}', "La franchise du conteneur {{container_number}} (dossier {{reference}}) expire le {{free_time_ends_at}}.\nPassé cette date, la compagnie facture l'immobilisation par jour entamé."],
        'invoice_available' => ['Facture disponible — {{invoice_number}}', "Votre facture {{invoice_number}} ({{total}} {{currency}}) est disponible dans votre espace client.\nDossier concerné : {{reference}}."],
    ];

    /**
     * @param  array<string, string|int|float|null>  $variables
     * @return string|null id de la notification créée, null si sautée (préférence désactivée / pas de destinataire)
     */
    public function dispatchToClient(
        string $tenantId,
        string $eventType,
        ?string $shipmentId,
        string $clientPartyId,
        array $variables,
    ): ?string {
        $portal = DB::table('portal_accounts')
            ->where('tenant_id', $tenantId)->where('party_id', $clientPartyId)->where('is_active', true)
            ->first(['id', 'email']);
        $email = $portal->email
            ?? DB::table('party_contacts')->where('party_id', $clientPartyId)->orderByDesc('is_primary')->value('email');

        if ($email === null) {
            return null; // aucun canal de contact — rien à envoyer
        }

        if ($portal !== null && ! $this->channelEnabled($tenantId, portalAccountId: $portal->id, eventType: $eventType)) {
            return $this->record($tenantId, $eventType, $shipmentId, $portal->id, $variables, $email, status: 'skipped');
        }

        return $this->record($tenantId, $eventType, $shipmentId, $portal->id ?? null, $variables, $email, status: 'queued', send: true);
    }

    /** Préférence explicite désactivée → false ; aucune ligne → activé (opt-out). */
    private function channelEnabled(string $tenantId, string $portalAccountId, string $eventType): bool
    {
        $enabled = DB::table('notification_preferences')
            ->where('tenant_id', $tenantId)
            ->where('portal_account_id', $portalAccountId)
            ->where('event_type', $eventType)
            ->where('channel', 'email')
            ->value('enabled');

        return $enabled === null || (bool) $enabled;
    }

    /** @param array<string, string|int|float|null> $variables */
    private function record(
        string $tenantId,
        string $eventType,
        ?string $shipmentId,
        ?string $portalAccountId,
        array $variables,
        string $email,
        string $status,
        bool $send = false,
    ): string {
        [$subject, $body] = $this->render($tenantId, $eventType, $variables);
        $now = now();

        $notificationId = (string) Str::uuid7();
        DB::table('notifications')->insert([
            'id' => $notificationId, 'tenant_id' => $tenantId,
            'event_type' => $eventType, 'shipment_id' => $shipmentId,
            'portal_account_id' => $portalAccountId,
            'title' => $subject, 'body' => $body,
            'payload' => json_encode($variables),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $deliveryId = (string) Str::uuid7();
        DB::table('notification_deliveries')->insert([
            'id' => $deliveryId, 'tenant_id' => $tenantId,
            'notification_id' => $notificationId,
            'channel' => 'email', 'recipient' => $email,
            'status' => $status, 'attempts' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        if ($send) {
            try {
                Mail::to($email)->send(new NotificationMail($subject, $body));
                DB::table('notification_deliveries')->where('id', $deliveryId)
                    ->update(['status' => 'sent', 'attempts' => 1, 'sent_at' => now(), 'updated_at' => now()]);
            } catch (Throwable $e) {
                DB::table('notification_deliveries')->where('id', $deliveryId)
                    ->update(['status' => 'failed', 'attempts' => 1, 'error' => mb_substr($e->getMessage(), 0, 1000), 'updated_at' => now()]);
                throw $e; // le worker outbox comptabilise l'échec et retentera
            }
        }

        return $notificationId;
    }

    /**
     * Template tenant (event_type × email × fr) sinon défaut embarqué, variables {{x}} substituées.
     *
     * @param  array<string, string|int|float|null>  $variables
     * @return array{0: string, 1: string} [sujet, corps]
     */
    private function render(string $tenantId, string $eventType, array $variables): array
    {
        $template = DB::table('notification_templates')
            ->where('tenant_id', $tenantId)->where('event_type', $eventType)
            ->where('channel', 'email')->where('locale', 'fr')->where('is_active', true)
            ->first(['subject', 'body']);

        [$subject, $body] = $template !== null
            ? [(string) ($template->subject ?? ''), (string) $template->body]
            : self::DEFAULTS[$eventType] ?? ['Notification — dossier {{reference}}', 'Mise à jour sur votre expédition {{reference}}.'];

        $replace = [];
        foreach ($variables as $key => $value) {
            $replace['{{'.$key.'}}'] = (string) ($value ?? '—');
        }

        return [strtr($subject, $replace), strtr($body, $replace)];
    }
}
