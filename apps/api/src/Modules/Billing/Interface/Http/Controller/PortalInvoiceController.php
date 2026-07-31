<?php

declare(strict_types=1);

namespace Silaris\Modules\Billing\Interface\Http\Controller;

use Barryvdh\DomPDF\Facade\Pdf;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Silaris\Modules\Billing\Domain\Service\ReceivableBalance;
use Silaris\Modules\Billing\Infrastructure\Persistence\Model\InvoiceModel;
use Silaris\Modules\Tenancy\Application\Service\BrandingResolver;
use Silaris\Modules\Tenancy\Infrastructure\Persistence\Model\CompanyModel;
use Symfony\Component\HttpFoundation\Response;

/** Portail — factures validées de la société uniquement (jamais les brouillons). */
class PortalInvoiceController
{
    /**
     * Relevé du client : ses factures avec ce qui reste dû, sa balance âgée, et
     * ses règlements. Le reste dû se lit sur les imputations réelles, pas sur le
     * `payment_status` (écrit par la synchronisation comptable, absent sans elle).
     */
    public function index(Request $request): JsonResponse
    {
        $partyId = $request->user()->party_id;

        $invoices = InvoiceModel::query()
            ->where('party_id', $partyId)
            ->where('status', '<>', 'draft')
            ->with('shipment:id,reference')
            ->orderByDesc('issue_date')
            ->limit(100)
            ->get(['id', 'type', 'number', 'status', 'currency_code', 'total_excl_tax', 'total_incl_tax', 'issue_date', 'due_date', 'shipment_id']);

        $allocated = $this->allocatedByInvoice($invoices->pluck('id')->all());

        $aged = [];
        $data = $invoices->map(function (InvoiceModel $invoice) use ($allocated, &$aged): array {
            $total = (float) $invoice->total_incl_tax;
            $paid = (float) ($allocated[$invoice->id] ?? 0.0);
            // L'avoir n'est pas une créance : il n'entre ni dans le reste dû ni
            // dans la balance âgée.
            $isInvoice = $invoice->type === 'invoice';
            $outstanding = $isInvoice ? ReceivableBalance::outstanding($total, $paid) : 0.0;

            if ($isInvoice && $outstanding > 0.0049 && $invoice->due_date !== null) {
                $aged[] = ['due_date' => new DateTimeImmutable($invoice->due_date->toDateString()), 'outstanding' => $outstanding];
            }

            return [
                'id' => $invoice->id,
                'type' => $invoice->type,
                'number' => $invoice->number,
                'currency_code' => $invoice->currency_code,
                'total_incl_tax' => $total,
                'paid' => $isInvoice ? round($paid, 2) : 0.0,
                'outstanding' => $outstanding,
                'pay_status' => $isInvoice ? ReceivableBalance::status($total, $paid) : 'n_a',
                'issue_date' => $invoice->issue_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'shipment' => $invoice->shipment,
            ];
        })->all();

        $summary = ReceivableBalance::aged($aged, new DateTimeImmutable('today'));

        return response()->json([
            'data' => $data,
            'summary' => $summary,
            'receipts' => $this->receipts($partyId),
        ]);
    }

    /**
     * Montant imputé par facture, règlements annulés exclus.
     *
     * @param  list<string>  $invoiceIds
     * @return array<string, float>
     */
    private function allocatedByInvoice(array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }

        return DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->whereIn('payment_allocations.invoice_id', $invoiceIds)
            ->whereNull('payments.cancelled_at')
            ->groupBy('payment_allocations.invoice_id')
            ->selectRaw('payment_allocations.invoice_id, sum(payment_allocations.amount) AS allocated')
            ->pluck('allocated', 'invoice_id')
            ->map(static fn ($v): float => (float) $v)
            ->all();
    }

    /**
     * Règlements du client, du plus récent au plus ancien. Un reçu annulé
     * n'apparaît pas : il n'a jamais soldé quoi que ce soit.
     *
     * @return list<array<string, mixed>>
     */
    private function receipts(string $partyId): array
    {
        return DB::table('payments')
            ->where('party_id', $partyId)
            ->whereNull('cancelled_at')
            ->orderByDesc('received_on')
            ->limit(50)
            ->get(['reference', 'method', 'amount', 'received_on', 'note'])
            ->map(static fn ($r): array => [
                'reference' => $r->reference,
                'method' => $r->method,
                'amount' => (float) $r->amount,
                'received_on' => $r->received_on,
                'note' => $r->note,
            ])->all();
    }

    /** GET /portal/invoices/{id}/pdf — facture du client connecté uniquement. */
    public function pdf(Request $request, string $invoiceId): Response
    {
        $invoice = InvoiceModel::with(['lines', 'party', 'shipment'])
            ->where('party_id', $request->user()->party_id)
            ->where('status', '<>', 'draft')
            ->findOrFail($invoiceId);
        $company = CompanyModel::findOrFail($invoice->company_id);

        $prefix = match ($invoice->type) {
            'credit_note' => 'avoir',
            'proforma' => 'proforma',
            default => 'facture',
        };

        return Pdf::loadView('pdf.invoice', ['invoice' => $invoice, 'company' => $company, 'logo' => app(BrandingResolver::class)->logoDataUri($company)])
            ->download($prefix.'-'.($invoice->number ?? substr($invoice->id, 0, 8)).'.pdf');
    }
}
