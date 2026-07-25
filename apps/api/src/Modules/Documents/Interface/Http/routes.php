<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Documents\Interface\Http\Controller\DocumentController;

Route::prefix('documents')->group(function (): void {
    Route::get('/', [DocumentController::class, 'index'])->can('documents.read');
    Route::post('/', [DocumentController::class, 'store'])->can('documents.create');
    Route::get('/{documentId}/download-url', [DocumentController::class, 'downloadUrl'])->whereUuid('documentId')->can('documents.download');
    Route::post('/{documentId}/archive', [DocumentController::class, 'archive'])->whereUuid('documentId')->can('documents.archive');
});
