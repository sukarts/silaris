<?php

declare(strict_types=1);

namespace Silaris\Modules\Identity\Application\Service;

use Illuminate\Validation\Rules\Password;

final class PasswordPolicy
{
    public static function rule(): Password
    {
        return Password::min(12)->mixedCase()->numbers()->symbols();
    }
}
