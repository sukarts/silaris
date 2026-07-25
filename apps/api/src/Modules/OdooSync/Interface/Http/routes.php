<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\OdooSync\Interface\Http\Controller\OdooController;

Route::prefix('odoo')->group(function (): void {
    Route::get('/status', [OdooController::class, 'status'])->can('odoo.read');
    Route::put('/config', [OdooController::class, 'configure'])->can('odoo.configure');
});
