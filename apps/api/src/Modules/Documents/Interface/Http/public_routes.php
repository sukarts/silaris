<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Documents\Interface\Http\Controller\DocumentController;

Route::get('/documents/download/{versionId}', [DocumentController::class, 'download'])
    ->whereUuid('versionId')
    ->name('documents.download');
