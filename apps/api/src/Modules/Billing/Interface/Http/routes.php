<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Billing\Interface\Http\Controller\InvoiceController;
use Silaris\Modules\Billing\Interface\Http\Controller\PaymentController;

Route::prefix('invoices')->group(function (): void {
    Route::get('/', [InvoiceController::class, 'index'])->can('invoices.read');
    Route::post('/', [InvoiceController::class, 'store'])->can('invoices.create');
    Route::post('/from-quote/{quoteId}', [InvoiceController::class, 'fromQuote'])->whereUuid('quoteId')->can('invoices.create');
    Route::get('/{invoiceId}', [InvoiceController::class, 'show'])->whereUuid('invoiceId')->can('invoices.read');
    Route::get('/{invoiceId}/pdf', [InvoiceController::class, 'pdf'])->whereUuid('invoiceId')->can('invoices.read');
    Route::patch('/{invoiceId}', [InvoiceController::class, 'update'])->whereUuid('invoiceId')->can('invoices.update');
    Route::post('/{invoiceId}/validate', [InvoiceController::class, 'validateInvoice'])->whereUuid('invoiceId')->can('invoices.validate');
    Route::post('/{invoiceId}/credit-note', [InvoiceController::class, 'creditNote'])->whereUuid('invoiceId')->can('invoices.credit');
});

Route::get('tax-rates', [InvoiceController::class, 'taxRates'])->can('invoices.read');

Route::prefix('payments')->group(function (): void {
    Route::get('/', [PaymentController::class, 'index'])->can('payments.read');
    Route::post('/', [PaymentController::class, 'store'])->can('payments.create');
    Route::get('/{paymentId}', [PaymentController::class, 'show'])->whereUuid('paymentId')->can('payments.read');
    Route::post('/{paymentId}/cancel', [PaymentController::class, 'cancel'])->whereUuid('paymentId')->can('payments.cancel');
});

// Le recouvrement se lit par créance, pas par encaissement : la balance âgée
// et le détail client vivent donc à part du journal des règlements.
Route::prefix('receivables')->group(function (): void {
    Route::get('/', [PaymentController::class, 'aged'])->can('payments.read');
    Route::get('/{partyId}', [PaymentController::class, 'outstanding'])->whereUuid('partyId')->can('payments.read');
});
