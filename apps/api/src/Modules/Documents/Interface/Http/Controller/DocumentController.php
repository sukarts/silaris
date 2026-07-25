<?php

declare(strict_types=1);

namespace Silaris\Modules\Documents\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Silaris\Modules\Documents\Infrastructure\Persistence\Model\DocumentModel;
use Silaris\Modules\Documents\Infrastructure\Persistence\Model\DocumentVersionModel;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController
{
    private const TYPES = ['bl', 'hbl', 'mbl', 'awb', 'commercial_invoice', 'packing_list', 'certificate_origin', 'insurance', 'customs', 'photo', 'contract', 'other'];

    private const MAX_SIZE_KB = 25 * 1024;

    private const ALLOWED_MIMES = ['pdf', 'png', 'jpg', 'jpeg', 'xlsx', 'xls', 'docx', 'doc', 'txt', 'csv'];

    public function __construct(private readonly TenantContext $tenant) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shipment_id' => ['sometimes', 'uuid'],
            'party_id' => ['sometimes', 'uuid'],
            'type' => ['sometimes', Rule::in(self::TYPES)],
            'status' => ['sometimes', Rule::in(['missing', 'received', 'validated'])],
        ]);

        return response()->json(
            DocumentModel::with(['versions' => fn ($q) => $q->limit(1)])
                ->when($validated['shipment_id'] ?? null, fn ($q, $s) => $q->where('shipment_id', $s))
                ->when($validated['party_id'] ?? null, fn ($q, $p) => $q->where('party_id', $p))
                ->when($validated['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
                ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->orderByDesc('created_at')
                ->cursorPaginate(50),
        );
    }

    /** POST /v1/documents — multipart. Nouveau document OU nouvelle version (document_id). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_id' => ['nullable', 'uuid', 'exists:documents,id'],
            'shipment_id' => ['nullable', 'uuid', 'exists:shipments,id'],
            'party_id' => ['nullable', 'uuid', 'exists:parties,id'],
            'type' => ['required_without:document_id', Rule::in(self::TYPES)],
            'title' => ['required_without:document_id', 'string', 'max:255'],
            'visibility' => ['sometimes', Rule::in(['internal', 'client', 'confidential'])],
            'file' => ['required', 'file', 'max:'.self::MAX_SIZE_KB, 'mimes:'.implode(',', self::ALLOWED_MIMES)],
        ]);

        $file = $request->file('file');

        $result = DB::transaction(function () use ($data, $file) {
            $document = isset($data['document_id'])
                ? DocumentModel::findOrFail($data['document_id'])
                : DocumentModel::create([
                    'shipment_id' => $data['shipment_id'] ?? null,
                    'party_id' => $data['party_id'] ?? null,
                    'type' => $data['type'],
                    'title' => $data['title'],
                    'visibility' => $data['visibility'] ?? 'internal',
                    'status' => 'received',
                ]);

            if ($document->status === 'missing') {
                $document->update(['status' => 'received']);
            }

            $version = ((int) $document->versions()->max('version')) + 1;
            $key = sprintf(
                'tenants/%s/documents/%s/v%d/%s',
                $this->tenant->id(), $document->id, $version, Str::random(40).'.'.$file->getClientOriginalExtension(),
            );
            Storage::disk(config('filesystems.documents_disk', 'local'))->put($key, $file->getContent());

            $documentVersion = $document->versions()->create([
                'version' => $version,
                's3_key' => $key,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => (string) $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'checksum_sha256' => hash_file('sha256', $file->getRealPath()),
                'av_scan_status' => 'pending', // job antivirus asynchrone
                'uploaded_by' => request()->user()?->id,
            ]);

            return [$document, $documentVersion];
        });

        [$document, $documentVersion] = $result;

        return response()->json(['document' => $document->fresh(), 'version' => $documentVersion], 201);
    }

    /** GET /v1/documents/{id}/download-url — URL signée temporaire (jamais d'accès direct). */
    public function downloadUrl(string $documentId): JsonResponse
    {
        $document = DocumentModel::findOrFail($documentId);
        $latest = $document->versions()->firstOrFail();

        return response()->json([
            'url' => URL::temporarySignedRoute('documents.download', now()->addMinutes(10), [
                'versionId' => $latest->id,
            ]),
            'expires_in' => 600,
            'filename' => $latest->original_filename,
        ]);
    }

    /** GET signé — journalise le téléchargement. */
    public function download(Request $request, string $versionId): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $version = DocumentVersionModel::withoutGlobalScopes()->findOrFail($versionId);

        DB::table('document_downloads')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $version->tenant_id,
            'document_version_id' => $version->id,
            'user_id' => $request->user()?->id,
            'ip' => $request->ip(),
        ]);

        return Storage::disk(config('filesystems.documents_disk', 'local'))
            ->download($version->s3_key, $version->original_filename);
    }

    /** POST /v1/documents/{id}/archive */
    public function archive(string $documentId): JsonResponse
    {
        $document = DocumentModel::findOrFail($documentId);
        $document->update(['is_archived' => true]);

        return response()->json($document->fresh());
    }
}
