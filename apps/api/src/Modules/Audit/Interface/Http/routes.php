<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Audit\Interface\Http\Controller\AuditLogController;

Route::get('/admin/audit-logs', [AuditLogController::class, 'index'])->can('audit.read');
