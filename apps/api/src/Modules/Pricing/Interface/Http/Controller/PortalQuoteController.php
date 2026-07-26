<?php

declare(strict_types=1);

namespace Silaris\Modules\Pricing\Interface\Http\Controller;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Silaris\Modules\Pricing\Infrastructure\Persistence\Model\QuoteModel;
use Silaris\Modules\Tenancy\Application\Service\BrandingResolver;
use Silaris\Modules\Tenancy\Infrastructure\Persistence\Model\CompanyModel;
use Symfony\Component\HttpFoundation\Response;

/** Portail — devis envoyés ; acceptation/refus en ligne. */
class PortalQuoteController
{
    public function index(Request $request): JsonResponse
    {
        $quotes = QuoteModel::query()
            ->where('party_id', $request->user()->party_id)
            ->whereIn('status', ['sent', 'accepted', 'rejected', 'expired'])
            ->with('lines')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => $quotes]);
    }

    public function accept(Request $request, string $quoteId): JsonResponse
    {
        $quote = QuoteModel::where('party_id', $request->user()->party_id)
            ->where('status', 'sent')
            ->findOrFail($quoteId);

        $quote->update(['status' => 'accepted', 'accepted_at' => now()]);
        // La création du dossier reste une décision interne (Étape 17 — accept côté agence).

        return response()->json($quote->fresh());
    }

    public function reject(Request $request, string $quoteId): JsonResponse
    {
        $quote = QuoteModel::where('party_id', $request->user()->party_id)
            ->where('status', 'sent')
            ->findOrFail($quoteId);

        $quote->update([
            'status' => 'rejected',
            'rejected_at' => now(),
            'rejection_reason' => $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? 'Refusé via portail',
        ]);

        return response()->json($quote->fresh());
    }

    /** GET /portal/quotes/{id}/pdf — cotation du client connecté uniquement. */
    public function pdf(Request $request, string $quoteId): Response
    {
        $quote = QuoteModel::with(['lines', 'party'])
            ->where('party_id', $request->user()->party_id)
            ->whereIn('status', ['sent', 'accepted', 'rejected', 'expired'])
            ->findOrFail($quoteId);
        $company = CompanyModel::findOrFail($quote->company_id);

        return Pdf::loadView('pdf.quote', ['quote' => $quote, 'company' => $company, 'logo' => app(BrandingResolver::class)->logoDataUri($company)])
            ->download('cotation-'.$quote->number.'.pdf');
    }
}
