<?php

declare(strict_types=1);

namespace Silaris\Modules\Notifications\Infrastructure\Mail;

/**
 * Notification métier (départ, arrivée, retard, facture…) rendue depuis un
 * template (tenant ou défaut) déjà résolu par le dispatcher.
 */
class NotificationMail extends GenericMail
{
    public function __construct(string $subject, string $body, ?string $shipmentReference = null)
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        parent::__construct(
            mailSubject: $subject,
            title: $subject,
            lines: array_values(array_filter(explode("\n", $body), fn (string $l) => trim($l) !== '')),
            ctaUrl: $frontend !== '' ? "{$frontend}/portal" : null,
            ctaLabel: 'Suivre mon expédition',
        );
    }
}
