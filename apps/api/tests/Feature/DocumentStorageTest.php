<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** Enregistre un document et sa version, sans déposer d'objet sur le disque. */
function seedDocumentVersion(array $ids, string $key): string
{
    $documentId = (string) Str::uuid7();
    $versionId = (string) Str::uuid7();

    DB::table('documents')->insert([
        'id' => $documentId, 'tenant_id' => $ids['tenant'], 'party_id' => $ids['client'], 'type' => 'commercial_invoice',
        'title' => 'Facture commerciale', 'visibility' => 'client', 'status' => 'received',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('document_versions')->insert([
        'id' => $versionId, 'tenant_id' => $ids['tenant'], 'document_id' => $documentId,
        'version' => 1, 's3_key' => $key, 'original_filename' => 'facture.pdf',
        'mime_type' => 'application/pdf', 'size_bytes' => 12, 'checksum_sha256' => str_repeat('a', 64),
        'av_scan_status' => 'clean', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $versionId;
}

function signedDownload(string $versionId): string
{
    return URL::temporarySignedRoute('documents.download', now()->addMinutes(10), ['versionId' => $versionId]);
}

it('télécharge le fichier déposé', function (): void {
    Storage::fake(config('filesystems.documents_disk', 'local'));
    $ids = seedCore();

    $key = 'tenants/'.$ids['tenant'].'/documents/facture.pdf';
    Storage::disk(config('filesystems.documents_disk', 'local'))->put($key, 'contenu pdf');
    $versionId = seedDocumentVersion($ids, $key);

    $this->get(signedDownload($versionId))
        ->assertOk()
        ->assertDownload('facture.pdf');

    // Le téléchargement est journalisé, y compris sans utilisateur authentifié.
    expect(DB::table('document_downloads')->where('document_version_id', $versionId)->exists())->toBeTrue();
});

it('répond 404 explicite quand le fichier a disparu du stockage, jamais une erreur serveur', function (): void {
    // Symptôme observé en production : la version existe en base, l'objet non.
    // download() interroge la taille avant de streamer et lève, ce qui rendait
    // une 500 « Server Error » illisible pour l'exploitant comme pour le client.
    Storage::fake(config('filesystems.documents_disk', 'local'));
    $ids = seedCore();

    $versionId = seedDocumentVersion($ids, 'tenants/'.$ids['tenant'].'/documents/disparu.pdf');

    $this->get(signedDownload($versionId))->assertNotFound();
});

it('refuse une URL de téléchargement non signée', function (): void {
    Storage::fake(config('filesystems.documents_disk', 'local'));
    $ids = seedCore();

    $versionId = seedDocumentVersion($ids, 'tenants/'.$ids['tenant'].'/documents/facture.pdf');

    $this->get("/api/v1/public/documents/download/{$versionId}")->assertForbidden();
});

it('rejette le dépôt quand le stockage refuse l écriture, plutôt que d enregistrer une version sans fichier', function (): void {
    // Le disque est configuré en throw => false : put() renvoie false au lieu de
    // lever. Sans contrôle, la version serait enregistrée et le document
    // apparaîtrait dans la liste en cassant au téléchargement.
    $ids = seedCore();

    Storage::shouldReceive('disk')->andReturn($refusing = Mockery::mock());
    $refusing->shouldReceive('put')->andReturn(false);

    freshAuth();
    $this->withToken(tokenFor($ids['user_service_manager']))
        ->post('/api/v1/documents', [
            'party_id' => $ids['client'],
            'type' => 'commercial_invoice',
            'title' => 'Facture commerciale',
            'file' => UploadedFile::fake()->create('facture.pdf', 10, 'application/pdf'),
        ])
        ->assertStatus(503);

    expect(DB::table('documents')->count())->toBe(0)
        ->and(DB::table('document_versions')->count())->toBe(0);
});
