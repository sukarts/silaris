<?php

declare(strict_types=1);

namespace Silaris\Modules\Notifications\Infrastructure\Mail;

/**
 * Invitation d'un utilisateur interne : identifiants provisoires + lien de connexion.
 */
class UserInvitationMail extends GenericMail
{
    public function __construct(string $firstName, string $email, string $temporaryPassword, string $tenantName)
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $ctaUrl = $frontend !== '' ? "{$frontend}/login" : null;

        parent::__construct(
            mailSubject: "Votre accès SILARIS — {$tenantName}",
            title: "Bienvenue, {$firstName}",
            lines: [
                "Un compte SILARIS vient d'être créé pour vous par {$tenantName}.",
                "Identifiant : {$email}",
                'Mot de passe provisoire (à changer dès la première connexion) :',
            ],
            code: $temporaryPassword,
            ctaUrl: $ctaUrl,
            ctaLabel: 'Se connecter',
        );
    }
}
