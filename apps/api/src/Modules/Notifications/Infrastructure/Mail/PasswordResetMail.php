<?php

declare(strict_types=1);

namespace Silaris\Modules\Notifications\Infrastructure\Mail;

/**
 * Réinitialisation de mot de passe : token valable 60 minutes.
 */
class PasswordResetMail extends GenericMail
{
    public function __construct(string $email, string $token)
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $ctaUrl = $frontend !== ''
            ? "{$frontend}/reset-password?email=".urlencode($email).'&token='.urlencode($token)
            : null;

        parent::__construct(
            mailSubject: 'Réinitialisation de votre mot de passe SILARIS',
            title: 'Réinitialisation de mot de passe',
            lines: [
                'Une réinitialisation de mot de passe a été demandée pour votre compte.',
                $ctaUrl !== null
                    ? 'Cliquez sur le bouton ci-dessous, ou utilisez le code suivant. Ce lien expire dans 60 minutes.'
                    : 'Utilisez le code suivant pour définir un nouveau mot de passe. Il expire dans 60 minutes.',
                "Si vous n'êtes pas à l'origine de cette demande, ignorez ce message.",
            ],
            code: $token,
            ctaUrl: $ctaUrl,
            ctaLabel: 'Réinitialiser mon mot de passe',
        );
    }
}
