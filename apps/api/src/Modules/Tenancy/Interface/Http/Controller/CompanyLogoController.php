<?php

declare(strict_types=1);

namespace Silaris\Modules\Tenancy\Interface\Http\Controller;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Sert le logo d'une société. Passer par l'API plutôt que par une URL signée
 * du stockage objet évite les expirations, les réglages CORS et les
 * différences de signature entre fournisseurs (S3, R2, MinIO).
 *
 * Lecture cross-tenant délibérée (connexion système) : le logo est l'enseigne
 * publique du transitaire, affichée au suivi public et sur ses documents.
 */
class CompanyLogoController
{
    public function __invoke(string $companyId): Response
    {
        $key = DB::connection(config('database.system_connection'))
            ->table('companies')->where('id', $companyId)->where('is_active', true)
            ->value('logo_document_id');

        abort_if($key === null || $key === '', 404);

        try {
            $disk = Storage::disk(config('filesystems.documents_disk', 'local'));
            abort_unless($disk->exists($key), 404);
            $contents = $disk->get($key);
        } catch (Throwable) {
            abort(404);
        }

        abort_if($contents === null, 404);

        return response($contents, 200, [
            'Content-Type' => match (strtolower(pathinfo($key, PATHINFO_EXTENSION))) {
                'png' => 'image/png',
                'webp' => 'image/webp',
                default => 'image/jpeg',
            },
            // Le nom de fichier change à chaque remplacement : cache long sans risque de logo périmé.
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
