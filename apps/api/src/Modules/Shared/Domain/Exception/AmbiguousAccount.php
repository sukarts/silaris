<?php

declare(strict_types=1);

namespace Silaris\Modules\Shared\Domain\Exception;

/** Email présent sur plusieurs tenants sans désambiguïsation — jamais de sélection arbitraire. */
final class AmbiguousAccount extends DomainException
{
    public static function make(): self
    {
        return new self('Ce compte existe sur plusieurs organisations — précisez votre organisation (sous-domaine) pour vous connecter.');
    }

    public function errorCode(): string
    {
        return 'auth.ambiguous_account';
    }
}
