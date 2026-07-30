<?php

declare(strict_types=1);

namespace Silaris\Modules\Billing\Infrastructure\Accounting;

use Silaris\Modules\Billing\Domain\Accounting\AccountingLedger;

/**
 * Absence de comptabilité tierce — le débouché par défaut.
 *
 * SILARIS se suffit à lui-même : sans connecteur configuré, une facture validée
 * n'attend rien d'un système extérieur. Ce débouché ne fait donc rien, et le dit
 * — c'est ce qui distingue « pas d'export demandé » d'« export en échec ».
 */
final class NullLedger implements AccountingLedger
{
    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function queueExport(string $tenantId, string $invoiceId): void
    {
        // Aucun débouché : rien à reporter.
    }
}
