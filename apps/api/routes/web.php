<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Service API uniquement : la racine renvoie une réponse JSON minimale,
// sans page d'accueil Laravel (qui expose les versions framework/PHP).
Route::get('/', fn () => response()->json([
    'service' => 'silaris-api',
    'status' => 'ok',
    'health' => '/api/v1/health',
]));
