<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Pricing\Interface\Http\Controller\QuoteController;
use Silaris\Modules\Pricing\Interface\Http\Controller\TariffController;

Route::prefix('quotes')->group(function (): void {
    Route::get('/', [QuoteController::class, 'index'])->can('quotes.read');
    Route::post('/', [QuoteController::class, 'store'])->can('quotes.create');
    Route::post('/calculate', [QuoteController::class, 'calculate'])->can('quotes.read');
    Route::get('/{quoteId}', [QuoteController::class, 'show'])->whereUuid('quoteId')->can('quotes.read');
    Route::post('/{quoteId}/send', [QuoteController::class, 'send'])->whereUuid('quoteId')->can('quotes.send');
    Route::post('/{quoteId}/accept', [QuoteController::class, 'accept'])->whereUuid('quoteId')->can('quotes.accept');
    Route::post('/{quoteId}/reject', [QuoteController::class, 'reject'])->whereUuid('quoteId')->can('quotes.accept');
});

Route::prefix('tariffs')->group(function (): void {
    Route::get('/', [TariffController::class, 'index'])->can('tariffs.read');
    Route::post('/', [TariffController::class, 'store'])->can('tariffs.create');
    Route::get('/{tariffId}', [TariffController::class, 'show'])->whereUuid('tariffId')->can('tariffs.read');
    Route::delete('/{tariffId}', [TariffController::class, 'destroy'])->whereUuid('tariffId')->can('tariffs.delete');
});
