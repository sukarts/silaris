<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Tenancy\Interface\Http\Controller\OrganizationController;

Route::prefix('admin')->group(function (): void {
    Route::get('/companies', [OrganizationController::class, 'companies'])->can('companies.read');
    Route::post('/companies', [OrganizationController::class, 'storeCompany'])->can('companies.create');
    Route::patch('/companies/{companyId}', [OrganizationController::class, 'updateCompany'])->whereUuid('companyId')->can('companies.update');
    Route::post('/companies/{companyId}/branches', [OrganizationController::class, 'storeBranch'])->whereUuid('companyId')->can('branches.create');
    Route::patch('/branches/{branchId}', [OrganizationController::class, 'updateBranch'])->whereUuid('branchId')->can('branches.update');
});
