<?php

declare(strict_types=1);

namespace Silaris\Modules\Notifications\Infrastructure\Mail;

/**
 * Invitation d'un client à son espace portail : identifiants provisoires + lien.
 */
class PortalInvitationMail extends GenericMail
{
    public function __construct(string $clientName, string $email, string $temporaryPassword, string $tenantName)
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $ctaUrl = $frontend !== '' ? "{$frontend}/portal/login" : null;

        parent::__construct(
            mailSubject: "Votre espace client — {$tenantName}",
            title: "Bienvenue, {$clientName}",
            lines: [
                "{$tenantName} vous ouvre l'accès à votre espace client : suivi de vos expéditions en temps réel, factures, cotations et documents.",
                "Identifiant : {$email}",
                'Mot de passe provisoire :',
            ],
            code: $temporaryPassword,
            ctaUrl: $ctaUrl,
            ctaLabel: 'Accéder à mon espace',
        );
    }
}
