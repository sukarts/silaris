<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Billing\Interface\Http\Controller\PortalInvoiceController;

Route::get('/invoices', [PortalInvoiceController::class, 'index']);
