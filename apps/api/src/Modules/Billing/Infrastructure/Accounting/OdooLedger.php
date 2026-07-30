<?php

declare(strict_types=1);

namespace Silaris\Modules\Billing\Infrastructure\Accounting;

use Silaris\Modules\Billing\Domain\Accounting\AccountingLedger;
use Silaris\Modules\OdooSync\Application\Job\PushInvoiceToOdoo;
use Silaris\Modules\OdooSync\Infrastructure\Transport\OdooClientFactory;

/**
 * Odoo comme débouché comptable — un adaptateur parmi ceux que le port admet.
 *
 * Le module OdooSync sait déjà pousser une facture dans Odoo ; cet adaptateur ne
 * fait que le brancher au port, sans que la facturation ait à connaître le
 * transport XML-RPC ni les modèles account.move. Remplacer Odoo par une autre
 * comptabilité, c'est écrire un autre adaptateur, pas toucher à la facture.
 */
final class OdooLedger implements AccountingLedger
{
    public function __construct(private readonly OdooClientFactory $factory) {}

    public function name(): string
    {
        return 'odoo';
    }

    public function isConfigured(): bool
    {
        return $this->factory->isConfigured();
    }

    public function queueExport(string $tenantId, string $invoiceId): void
    {
        // Report asynchrone : le job gère les reprises et inscrit l'issue.
        PushInvoiceToOdoo::dispatch($tenantId, $invoiceId)->afterCommit();
    }
}
