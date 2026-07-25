<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Documents\Interface\Http\Controller\PortalDocumentController;

Route::get('/documents', [PortalDocumentController::class, 'index']);
Route::get('/documents/{documentId}/download-url', [PortalDocumentController::class, 'downloadUrl'])->whereUuid('documentId');
