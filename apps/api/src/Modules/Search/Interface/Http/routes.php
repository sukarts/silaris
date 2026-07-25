<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Search\Interface\Http\Controller\SearchController;

// Recherche globale — filtrée par permissions dans le contrôleur.
Route::get('/search', SearchController::class);
