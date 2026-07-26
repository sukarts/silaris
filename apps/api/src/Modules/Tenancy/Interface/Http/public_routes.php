<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Tenancy\Interface\Http\Controller\CompanyLogoController;

// Logo de la société servi par l'API : indépendant des URL signées du
// stockage objet (R2/S3), donc affichable sans expiration ni réglage CORS.
// Contenu non sensible : c'est l'enseigne, déjà présente sur les documents.
Route::get('/companies/{companyId}/logo', CompanyLogoController::class)->whereUuid('companyId');
