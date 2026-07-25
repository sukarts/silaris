<?php

declare(strict_types=1);

namespace Silaris\Modules\OdooSync\Application\Translator;

use Silaris\Modules\Billing\Infrastructure\Persistence\Model\InvoiceModel;

/**
 * invoices SILARIS → account.move Odoo.
 * SILARIS n'écrit JAMAIS d'écriture comptable : il pousse un document
 * account.move en brouillon ; la validation comptable se fait dans Odoo.
 */
final class InvoiceTranslator
{
    /** @return array<string, mixed> */
    public function toOdoo(InvoiceModel $invoice, int $odooPartnerId, array $taxIdByRateId = []): array
    {
        $lines = $invoice->lines->map(function ($line) use ($taxIdByRateId) {
            $taxes = [];
            if ($line->tax_rate_id !== null && isset($taxIdByRateId[$line->tax_rate_id])) {
                $taxes = [[6, 0, [$taxIdByRateId[$line->tax_rate_id]]]];
            }

            return [0, 0, [
                'name' => $line->description,
                'quantity' => (float) $line->quantity,
                'price_unit' => (float) $line->unit_price,
                'tax_ids' => $taxes,
            ]];
        })->all();

        return [
            'move_type' => $invoice->type === 'credit_note' ? 'out_refund' : 'out_invoice',
            'partner_id' => $odooPartnerId,
            'ref' => $invoice->number,
            'invoice_date' => $invoice->issue_date?->toDateString(),
            'invoice_date_due' => $invoice->due_date?->toDateString(),
            'currency_id' => null, // résolu par nom de devise côté job (res.currency)
            'invoice_line_ids' => $lines,
            'narration' => 'SILARIS '.$invoice->number.($invoice->shipment ? ' — dossier '.$invoice->shipment->reference : ''),
        ];
    }
}
