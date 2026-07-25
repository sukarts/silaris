<?php

declare(strict_types=1);

namespace Silaris\Modules\Shared\Domain\Exception;

use DomainException as BaseDomainException;

/**
 * Base des exceptions métier — traduites en 422 par le handler HTTP.
 */
abstract class DomainException extends BaseDomainException
{
    abstract public function errorCode(): string;
}
