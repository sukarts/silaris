<?php

declare(strict_types=1);

namespace Silaris\Modules\Pricing\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Silaris\Modules\Pricing\Infrastructure\Persistence\Model\QuoteModel;

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
}
