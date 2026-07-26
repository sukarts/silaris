<?php

declare(strict_types=1);

namespace Silaris\Modules\Tenancy\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Silaris\Modules\Shipment\Infrastructure\Persistence\SequenceReferenceGenerator;
use Silaris\Modules\Tenancy\Application\Service\BranchCodeGenerator;
use Silaris\Modules\Tenancy\Application\Service\BrandingResolver;
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

        if (is_string($request->input('country_code'))) {
            $request->merge(['country_code' => strtoupper($request->string('country_code')->toString())]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string', 'max:8', 'alpha_num:ascii'],
            'kind' => ['sometimes', Rule::in(['own', 'partner'])],
            'partner_name' => ['nullable', 'string', 'max:255'],
            // La localisation alimente le code : elle n'est exigée que lorsque
            // le transitaire laisse SILARIS le générer.
            'country_code' => ['required_without:code', 'nullable', 'size:2', 'exists:countries,code2'],
            'city' => ['required_without:code', 'nullable', 'string', 'max:120'],
            'locode' => ['sometimes', 'nullable', 'size:5'],
            'timezone' => ['sometimes', 'timezone:all'],
            'address' => ['sometimes', 'array'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        // Code normalisé UN/LOCODE si l'utilisateur n'impose rien.
        $data['kind'] ??= 'own';
        if (($data['code'] ?? '') === '') {
            $data['code'] = app(BranchCodeGenerator::class)
                ->generate($data['country_code'], $data['city'], $data['locode'] ?? null);
        }

        return response()->json(BranchModel::create([...$data, 'company_id' => $companyId]), 201);
    }

    /**
     * GET /v1/admin/branches/code-preview — code proposé pour un pays/ville,
     * sans créer l'agence.
     */
    public function branchCodePreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country_code' => ['required', 'size:2'],
            'city' => ['required', 'string', 'max:120'],
            'locode' => ['sometimes', 'nullable', 'size:5'],
        ]);

        return response()->json([
            'code' => app(BranchCodeGenerator::class)->generate(
                strtoupper($data['country_code']), $data['city'], $data['locode'] ?? null,
            ),
        ]);
    }

    public function updateBranch(Request $request, string $branchId): JsonResponse
    {
        $branch = BranchModel::findOrFail($branchId);
        $branch->update($request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'kind' => ['sometimes', Rule::in(['own', 'partner'])],
            'partner_name' => ['nullable', 'string', 'max:255'],
            'country_code' => ['sometimes', 'size:2', 'exists:countries,code2'],
            'city' => ['sometimes', 'string', 'max:120'],
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

        return response()->json(['logo_document_id' => $key, 'logo_url' => app(BrandingResolver::class)->logoUrl($company->id, $key)]);
    }

    /** GET /v1/admin/companies/{id}/logo-url — URL signée temporaire du logo. */
    public function logoUrlFor(string $companyId): JsonResponse
    {
        $company = CompanyModel::findOrFail($companyId);
        if ($company->logo_document_id === null) {
            return response()->json(['logo_url' => null]);
        }

        return response()->json(['logo_url' => app(BrandingResolver::class)->logoUrl($company->id, $company->logo_document_id)]);
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
}
