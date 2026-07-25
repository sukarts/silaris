<?php

declare(strict_types=1);

namespace Silaris\Modules\Billing\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Silaris\Modules\Billing\Infrastructure\Persistence\Model\InvoiceModel;

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
}
