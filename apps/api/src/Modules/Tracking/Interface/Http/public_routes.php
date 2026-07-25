<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Tracking\Interface\Http\Controller\PublicTrackingController;

Route::get('/tracking', [PublicTrackingController::class, 'show']);
