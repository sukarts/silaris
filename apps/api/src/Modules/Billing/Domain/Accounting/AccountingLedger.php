<?php

declare(strict_types=1);

namespace Silaris\Modules\Billing\Domain\Accounting;

/**
 * Débouché comptable d'une facture validée.
 *
 * SILARIS établit et certifie ses factures ; leur report en comptabilité est un
 * débouché, pas une étape de leur vie. Ce port dit ce qu'on attend d'une
 * comptabilité — qu'elle reçoive une facture — sans rien présumer de laquelle :
 * Odoo aujourd'hui, un autre ERP, un fichier FEC, un webhook demain. Chaque
 * comptabilité est un adaptateur ; la facturation n'en connaît qu'un contrat.
 */
interface AccountingLedger
{
    /** Nom du connecteur, pour la traçabilité. */
    public function name(): string;

    /** Un débouché est-il réellement branché ? Sinon, rien à exporter. */
    public function isConfigured(): bool;

    /**
     * Programme le report de la facture en comptabilité. L'appel ne bloque pas :
     * le report est asynchrone, son issue s'inscrit sur accounting_export_status.
     */
    public function queueExport(string $tenantId, string $invoiceId): void;
}
