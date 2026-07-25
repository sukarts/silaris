<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Referential\Interface\Http\Controller\ReferentialController;

Route::get('/referentials/{referential}', [ReferentialController::class, 'index'])
    ->whereIn('referential', ['countries', 'currencies', 'ports', 'airports', 'incoterms', 'carriers', 'airlines', 'goods_types']);
