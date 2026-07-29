<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Domain\Exception;

use Silaris\Modules\Shared\Domain\Exception\DomainException;

/** Une demande de passage d'étape est déjà ouverte sur ce dossier. */
final class StepRequestPending extends DomainException
{
    public static function already(string $toStep): self
    {
        return new self("Un passage à « {$toStep} » attend déjà la validation du responsable exploitation.");
    }

    public function errorCode(): string
    {
        return 'shipment.step_request_pending';
    }
}
