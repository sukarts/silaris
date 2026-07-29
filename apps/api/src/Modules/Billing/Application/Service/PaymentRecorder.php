<?php

declare(strict_types=1);

namespace Silaris\Modules\Billing\Application\Service;

use Illuminate\Support\Facades\DB;
use Silaris\Modules\Billing\Domain\Service\ReceivableBalance;
use Silaris\Modules\Billing\Infrastructure\Persistence\Model\InvoiceModel;
use Silaris\Modules\Billing\Infrastructure\Persistence\Model\PaymentAllocationModel;
use Silaris\Modules\Billing\Infrastructure\Persistence\Model\PaymentModel;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;

/**
 * Enregistre les encaissements et en tire l'état de paiement des factures.
 *
 * Point de passage unique : c'est la condition pour que `payment_status` ne
 * puisse jamais contredire les imputations. Toute autre écriture directe de
 * cette colonne rouvrirait l'écart qu'on vient de fermer.
 */
final class PaymentRecorder
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  list<array{invoice_id: string, amount: float}>|null  $allocations  Null = imputation au plus ancien
     */
    public function record(array $payment, ?array $allocations, ?string $userId): PaymentModel
    {
        return DB::transaction(function () use ($payment, $allocations, $userId): PaymentModel {
            $amount = round((float) $payment['amount'], 2);

            $lines = $allocations ?? ReceivableBalance::allocateOldestFirst(
                $amount,
                $this->outstandingOf($payment['party_id'], (string) $payment['currency_code']),
            )['allocations'];

            $this->assertAllocationsFit($amount, $lines, $payment);

            $record = PaymentModel::create([
                'tenant_id' => $this->tenant->id(),
                'company_id' => $payment['company_id'],
                'party_id' => $payment['party_id'],
                'reference' => $this->nextReference((string) $payment['company_id']),
                'method' => $payment['method'],
                'method_reference' => $payment['method_reference'] ?? null,
                'currency_code' => $payment['currency_code'],
                'amount' => $amount,
                'received_on' => $payment['received_on'],
                'note' => $payment['note'] ?? null,
                'recorded_by' => $userId,
            ]);

            foreach ($lines as $line) {
                PaymentAllocationModel::create([
                    'tenant_id' => $this->tenant->id(),
                    'payment_id' => $record->id,
                    'invoice_id' => $line['invoice_id'],
                    'amount' => round((float) $line['amount'], 2),
                ]);
                $this->refreshInvoiceStatus((string) $line['invoice_id']);
            }

            return $record;
        });
    }

    /**
     * Annule un encaissement sans l'effacer : un chèque revenu impayé ou une
     * saisie erronée restent des faits, et les factures qu'il soldait
     * redeviennent dues.
     */
    public function cancel(PaymentModel $payment, string $reason, ?string $userId): PaymentModel
    {
        return DB::transaction(function () use ($payment, $reason, $userId): PaymentModel {
            $invoiceIds = $payment->allocations()->pluck('invoice_id')->all();

            $payment->allocations()->delete();
            $payment->update([
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancel_reason' => $reason,
            ]);

            foreach ($invoiceIds as $invoiceId) {
                $this->refreshInvoiceStatus((string) $invoiceId);
            }

            return $payment->fresh();
        });
    }

    /**
     * Factures encore dues d'un client, de la plus ancienne à la plus récente.
     * Les brouillons en sont exclus : une facture non validée n'a pas d'échéance
     * et ne se règle pas.
     *
     * @return list<array{invoice_id: string, company_id: string, number: string, due_date: string, total: float, allocated: float, outstanding: float}>
     */
    public function outstandingOf(string $partyId, ?string $currency = null): array
    {
        $invoices = InvoiceModel::query()
            ->where('party_id', $partyId)
            ->where('type', 'invoice')
            ->where('status', '!=', 'draft')
            ->when($currency !== null, fn ($query) => $query->where('currency_code', $currency))
            ->orderBy('due_date')
            ->orderBy('number')
            ->get(['id', 'company_id', 'number', 'due_date', 'currency_code', 'total_incl_tax']);

        $allocated = $this->allocatedByInvoice($invoices->pluck('id')->all());

        return $invoices
            ->map(function (InvoiceModel $invoice) use ($allocated): array {
                $paid = (float) ($allocated[$invoice->id] ?? 0.0);
                $total = (float) $invoice->total_incl_tax;

                return [
                    'invoice_id' => (string) $invoice->id,
                    // La société encaissante se déduit de la facture : c'est
                    // elle qui porte la séquence légale du reçu.
                    'company_id' => (string) $invoice->company_id,
                    'number' => (string) $invoice->number,
                    'due_date' => (string) $invoice->due_date?->toDateString(),
                    'currency_code' => (string) $invoice->currency_code,
                    'total' => round($total, 2),
                    'allocated' => round($paid, 2),
                    'outstanding' => ReceivableBalance::outstanding($total, $paid),
                ];
            })
            ->filter(fn (array $row): bool => $row['outstanding'] > 0.0049)
            ->values()
            ->all();
    }

    /**
     * Toutes les créances en cours, regroupées par client, en deux requêtes.
     *
     * La balance âgée porte sur l'ensemble du portefeuille : la calculer client
     * par client ferait autant d'allers-retours que de tiers, ce qui devient
     * intenable dès quelques centaines de clients.
     *
     * @return array<string, list<array{invoice_id: string, number: string, due_date: string, outstanding: float}>>
     */
    public function outstandingByParty(?string $partyId = null): array
    {
        $invoices = InvoiceModel::query()
            ->where('type', 'invoice')
            ->where('status', '!=', 'draft')
            ->where('payment_status', '!=', 'paid')
            ->when($partyId !== null, fn ($query) => $query->where('party_id', $partyId))
            ->orderBy('due_date')
            ->get(['id', 'party_id', 'number', 'due_date', 'currency_code', 'total_incl_tax']);

        $allocated = $this->allocatedByInvoice($invoices->pluck('id')->all());

        $byParty = [];
        foreach ($invoices as $invoice) {
            $outstanding = ReceivableBalance::outstanding(
                (float) $invoice->total_incl_tax,
                (float) ($allocated[$invoice->id] ?? 0.0),
            );

            if ($outstanding <= 0.0049 || $invoice->due_date === null) {
                continue;
            }

            $byParty[(string) $invoice->party_id][] = [
                'invoice_id' => (string) $invoice->id,
                'number' => (string) $invoice->number,
                'due_date' => $invoice->due_date->toDateString(),
                'currency_code' => (string) $invoice->currency_code,
                'outstanding' => $outstanding,
            ];
        }

        return $byParty;
    }

    /** Recalcule l'état d'une facture depuis ses seules imputations. */
    public function refreshInvoiceStatus(string $invoiceId): void
    {
        $invoice = InvoiceModel::find($invoiceId);
        if ($invoice === null || $invoice->isDraft()) {
            return;
        }

        $allocated = (float) ($this->allocatedByInvoice([$invoiceId])[$invoiceId] ?? 0.0);

        $invoice->update([
            'payment_status' => ReceivableBalance::status((float) $invoice->total_incl_tax, $allocated),
        ]);
    }

    /**
     * @param  list<string>  $invoiceIds
     * @return array<string, float>
     */
    private function allocatedByInvoice(array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }

        return PaymentAllocationModel::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->groupBy('invoice_id')
            ->selectRaw('invoice_id, SUM(amount) AS total')
            ->pluck('total', 'invoice_id')
            ->map(static fn ($total): float => (float) $total)
            ->all();
    }

    /**
     * Deux dépassements possibles, tous deux refusés : imputer plus que la
     * somme reçue, ou solder une facture au-delà de son montant. Le second est
     * le plus traître — il rendrait un client créditeur sans qu'aucun avoir
     * n'existe.
     *
     * @param  list<array{invoice_id: string, amount: float}>  $lines
     * @param  array<string, mixed>  $payment
     */
    private function assertAllocationsFit(float $amount, array $lines, array $payment): void
    {
        $sum = round(array_sum(array_map(static fn (array $l): float => round((float) $l['amount'], 2), $lines)), 2);

        abort_if($sum > $amount + 0.0049, 422, 'Les imputations dépassent le montant reçu.');

        foreach ($lines as $line) {
            $invoice = InvoiceModel::find($line['invoice_id']);

            abort_if($invoice === null, 422, 'Facture inconnue dans les imputations.');
            abort_if($invoice->isDraft(), 422, "Une facture en brouillon ne peut pas être réglée : {$invoice->id}");
            abort_if(
                (string) $invoice->party_id !== (string) $payment['party_id'],
                422,
                "La facture {$invoice->number} appartient à un autre client.",
            );
            abort_if(
                (string) $invoice->currency_code !== (string) $payment['currency_code'],
                422,
                "La facture {$invoice->number} est en {$invoice->currency_code}, le règlement en {$payment['currency_code']}.",
            );

            $already = (float) ($this->allocatedByInvoice([(string) $invoice->id])[$invoice->id] ?? 0.0);
            $rest = ReceivableBalance::outstanding((float) $invoice->total_incl_tax, $already);

            abort_if(
                round((float) $line['amount'], 2) > $rest + 0.0049,
                422,
                "La facture {$invoice->number} ne doit plus que {$rest} — imputation refusée.",
            );
        }
    }

    /** Numéro de reçu séquencé par société, comme la numérotation des factures. */
    private function nextReference(string $companyId): string
    {
        $sequence = DB::selectOne('SELECT next_sequence(?, ?) AS value', [
            $this->tenant->id(), "payment:{$companyId}",
        ])->value;

        return sprintf('REC-%s-%04d', date('Y'), $sequence);
    }
}
