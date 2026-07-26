<?php

declare(strict_types=1);

namespace Silaris\Modules\Billing\Interface\Http\Controller;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Silaris\Modules\Billing\Infrastructure\Persistence\Model\InvoiceModel;
use Silaris\Modules\Tenancy\Application\Service\BrandingResolver;
use Silaris\Modules\Tenancy\Infrastructure\Persistence\Model\CompanyModel;
use Symfony\Component\HttpFoundation\Response;

/** Portail — factures validées de la société uniquement (jamais les brouillons). */
class PortalInvoiceController
{
    public function index(Request $request): JsonResponse
    {
        $invoices = InvoiceModel::query()
            ->where('party_id', $request->user()->party_id)
            ->where('status', '<>', 'draft')
            ->with('shipment:id,reference')
            ->orderByDesc('issue_date')
            ->limit(50)
            ->get(['id', 'type', 'number', 'status', 'payment_status', 'currency_code', 'total_excl_tax', 'total_incl_tax', 'issue_date', 'due_date', 'shipment_id']);

        return response()->json(['data' => $invoices]);
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
