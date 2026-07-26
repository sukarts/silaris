<?php

declare(strict_types=1);

namespace Silaris\Modules\Tenancy\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Silaris\Modules\Shipment\Infrastructure\Persistence\SequenceReferenceGenerator;
use Silaris\Modules\Tenancy\Infrastructure\Persistence\Model\BranchModel;
use Silaris\Modules\Tenancy\Infrastructure\Persistence\Model\CompanyModel;

class OrganizationController
{
    private const LOGO_MAX_KB = 2048;

    public function __construct(private readonly TenantContext $tenant) {}

    public function companies(): JsonResponse
    {
        return response()->json(CompanyModel::with('branches')->orderBy('legal_name')->get());
    }

    public function storeCompany(Request $request): JsonResponse
    {
        return response()->json(CompanyModel::create($request->validate([
            'legal_name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:16', 'alpha_num:ascii'],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'currency_code' => ['required', 'size:3', 'exists:currencies,code'],
            'address' => ['sometimes', 'array'],
            'invoice_settings' => ['sometimes', 'array'],
            'shipment_settings' => ['sometimes', 'array'],
        ])), 201);
    }

    public function updateCompany(Request $request, string $companyId): JsonResponse
    {
        $company = CompanyModel::findOrFail($companyId);
        $company->update($request->validate([
            'legal_name' => ['sometimes', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'currency_code' => ['sometimes', 'size:3', 'exists:currencies,code'],
            'address' => ['sometimes', 'array'],
            'invoice_settings' => ['sometimes', 'array'],
            'shipment_settings' => ['sometimes', 'array'],
            'shipment_settings.reference_format' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/\\{SEQ:[1-9]\\}/'],
            'shipment_settings.reference_prefix' => ['sometimes', 'nullable', 'string', 'max:16'],
            'is_active' => ['sometimes', 'boolean'],
        ]));

        return response()->json($company->fresh('branches'));
    }

    public function storeBranch(Request $request, string $companyId): JsonResponse
    {
        CompanyModel::findOrFail($companyId);

        return response()->json(BranchModel::create([
            ...$request->validate([
                'name' => ['required', 'string', 'max:255'],
                'code' => ['required', 'string', 'max:8', 'alpha_num:ascii'],
                'timezone' => ['sometimes', 'timezone:all'],
                'address' => ['sometimes', 'array'],
                'phone' => ['nullable', 'string', 'max:32'],
                'email' => ['nullable', 'email', 'max:255'],
            ]),
            'company_id' => $companyId,
        ]), 201);
    }

    public function updateBranch(Request $request, string $branchId): JsonResponse
    {
        $branch = BranchModel::findOrFail($branchId);
        $branch->update($request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'timezone' => ['sometimes', 'timezone:all'],
            'address' => ['sometimes', 'array'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]));

        return response()->json($branch->fresh());
    }

    /** POST /v1/admin/companies/{id}/logo — logo de la société (en-têtes PDF, portail). */
    public function uploadLogo(Request $request, string $companyId): JsonResponse
    {
        $company = CompanyModel::findOrFail($companyId);
        $request->validate([
            'logo' => ['required', 'file', 'max:'.self::LOGO_MAX_KB, 'mimes:png,jpg,jpeg,webp'],
        ]);

        $file = $request->file('logo');
        $key = sprintf(
            'tenants/%s/branding/logo-%s.%s',
            $this->tenant->id(), Str::random(16), $file->getClientOriginalExtension(),
        );
        Storage::disk(config('filesystems.documents_disk', 'local'))->put($key, $file->getContent());

        $previous = $company->logo_document_id;
        $company->update(['logo_document_id' => $key]);
        if ($previous !== null && $previous !== $key) {
            Storage::disk(config('filesystems.documents_disk', 'local'))->delete($previous);
        }

        return response()->json(['logo_document_id' => $key, 'logo_url' => $this->logoUrl($key)]);
    }

    /** GET /v1/admin/companies/{id}/logo-url — URL signée temporaire du logo. */
    public function logoUrlFor(string $companyId): JsonResponse
    {
        $company = CompanyModel::findOrFail($companyId);
        if ($company->logo_document_id === null) {
            return response()->json(['logo_url' => null]);
        }

        return response()->json(['logo_url' => $this->logoUrl($company->logo_document_id)]);
    }

    /**
     * GET /v1/admin/companies/{id}/reference-preview — aperçu du format de
     * référence dossier, sans consommer de séquence.
     */
    public function referencePreview(Request $request, string $companyId): JsonResponse
    {
        $company = CompanyModel::with('branches')->findOrFail($companyId);
        $data = $request->validate([
            'format' => ['sometimes', 'string', 'max:64'],
            'prefix' => ['sometimes', 'nullable', 'string', 'max:16'],
        ]);

        $settings = $company->shipment_settings ?? [];
        $format = $data['format'] ?? $settings['reference_format'] ?? SequenceReferenceGenerator::DEFAULT_FORMAT;
        $prefix = $data['prefix'] ?? $settings['reference_prefix'] ?? $company->code;
        $branchCode = (string) ($company->branches->first()->code ?? 'HQ');

        return response()->json([
            'preview' => SequenceReferenceGenerator::render($format, [
                'PREFIX' => (string) $prefix,
                'COMPANY' => (string) $company->code,
                'BRANCH' => $branchCode,
                'YEAR' => date('Y'),
                'YY' => date('y'),
                'MONTH' => date('m'),
            ], 128),
        ]);
    }

    /** URL signée (S3/R2) ; repli sur l'URL publique pour les disques locaux. */
    private function logoUrl(string $key): string
    {
        $disk = Storage::disk(config('filesystems.documents_disk', 'local'));

        try {
            return $disk->temporaryUrl($key, now()->addHours(6));
        } catch (\RuntimeException) {
            return $disk->url($key);
        }
    }
}
