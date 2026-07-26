<?php

declare(strict_types=1);

namespace Silaris\Modules\Tenancy\Application\Service;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Silaris\Modules\Tenancy\Infrastructure\Persistence\Model\CompanyModel;
use Throwable;

/**
 * Identité visible par les clients finaux : SILARIS est la solution, le
 * transitaire est la marque. Fournit le logo sous deux formes — data URI
 * (embarqué dans les PDF, aucun appel réseau au rendu) et URL signée
 * temporaire (pages web).
 */
final class BrandingResolver
{
    private const MAX_EMBED_BYTES = 2_097_152; // 2 Mo — garde-fou mémoire dompdf

    /** Logo encodé pour un gabarit PDF ; null si absent ou illisible. */
    public function logoDataUri(?CompanyModel $company): ?string
    {
        $key = $company?->logo_document_id;
        if ($key === null || $key === '') {
            return null;
        }

        try {
            $disk = Storage::disk(config('filesystems.documents_disk', 'local'));
            if (! $disk->exists($key) || $disk->size($key) > self::MAX_EMBED_BYTES) {
                return null;
            }
            $contents = $disk->get($key);
            if ($contents === null) {
                return null;
            }

            return 'data:'.$this->mimeFor($key).';base64,'.base64_encode($contents);
        } catch (Throwable) {
            return null; // un logo illisible ne doit jamais empêcher l'émission d'un document
        }
    }

    /** URL temporaire du logo pour les pages web ; null si absent. */
    public function logoUrl(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        $disk = Storage::disk(config('filesystems.documents_disk', 'local'));

        try {
            return $disk->temporaryUrl($key, now()->addHours(6));
        } catch (RuntimeException) {
            return $disk->url($key);
        } catch (Throwable) {
            return null;
        }
    }

    private function mimeFor(string $key): string
    {
        return match (strtolower(pathinfo($key, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }
}
