<?php

declare(strict_types=1);

namespace Silaris\Modules\Tenancy\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Silaris\Modules\Tenancy\Infrastructure\Persistence\Model\BranchModel;
use Silaris\Modules\Tenancy\Infrastructure\Persistence\Model\CompanyModel;

class OrganizationController
{
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
        ])), 201);
    }

    public function updateCompany(Request $request, string $companyId): JsonResponse
    {
        $company = CompanyModel::findOrFail($companyId);
        $company->update($request->validate([
            'legal_name' => ['sometimes', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'address' => ['sometimes', 'array'],
            'invoice_settings' => ['sometimes', 'array'],
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
}
