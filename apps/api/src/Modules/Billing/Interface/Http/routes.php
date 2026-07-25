<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Billing\Interface\Http\Controller\InvoiceController;

Route::prefix('invoices')->group(function (): void {
    Route::get('/', [InvoiceController::class, 'index'])->can('invoices.read');
    Route::post('/', [InvoiceController::class, 'store'])->can('invoices.create');
    Route::get('/{invoiceId}', [InvoiceController::class, 'show'])->whereUuid('invoiceId')->can('invoices.read');
    Route::patch('/{invoiceId}', [InvoiceController::class, 'update'])->whereUuid('invoiceId')->can('invoices.update');
    Route::post('/{invoiceId}/validate', [InvoiceController::class, 'validateInvoice'])->whereUuid('invoiceId')->can('invoices.validate');
    Route::post('/{invoiceId}/credit-note', [InvoiceController::class, 'creditNote'])->whereUuid('invoiceId')->can('invoices.credit');
});
