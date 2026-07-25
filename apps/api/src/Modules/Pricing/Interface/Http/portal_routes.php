<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Pricing\Interface\Http\Controller\PortalQuoteController;

Route::get('/quotes', [PortalQuoteController::class, 'index']);
Route::post('/quotes/{quoteId}/accept', [PortalQuoteController::class, 'accept'])->whereUuid('quoteId');
Route::post('/quotes/{quoteId}/reject', [PortalQuoteController::class, 'reject'])->whereUuid('quoteId');
