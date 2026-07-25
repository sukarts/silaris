<?php

declare(strict_types=1);

namespace Silaris\Modules\Documents\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Silaris\Modules\Documents\Infrastructure\Persistence\Model\DocumentModel;

/** Portail — uniquement les documents visibility=client des dossiers de la société. */
class PortalDocumentController
{
    public function index(Request $request): JsonResponse
    {
        $partyId = $request->user()->party_id;

        $documents = DocumentModel::query()
            ->where('visibility', 'client')
            ->where('status', '<>', 'missing')
            ->where(fn ($q) => $q
                ->whereIn('shipment_id', fn ($sub) => $sub->select('id')->from('shipments')->where('client_id', $partyId))
                ->orWhere('party_id', $partyId))
            ->with(['versions' => fn ($q) => $q->limit(1)])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json(['data' => $documents]);
    }

    public function downloadUrl(Request $request, string $documentId): JsonResponse
    {
        $partyId = $request->user()->party_id;

        $document = DocumentModel::where('visibility', 'client')
            ->where(fn ($q) => $q
                ->whereIn('shipment_id', fn ($sub) => $sub->select('id')->from('shipments')->where('client_id', $partyId))
                ->orWhere('party_id', $partyId))
            ->findOrFail($documentId);

        $latest = $document->versions()->firstOrFail();

        return response()->json([
            'url' => URL::temporarySignedRoute('documents.download', now()->addMinutes(10), ['versionId' => $latest->id]),
            'filename' => $latest->original_filename,
        ]);
    }
}
