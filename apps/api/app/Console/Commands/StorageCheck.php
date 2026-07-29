<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Silaris\Modules\Documents\Infrastructure\Persistence\Model\DocumentVersionModel;
use Throwable;

/**
 * Contrôle de l'espace de stockage des documents.
 *
 * Le disque est configuré en throw => false : une écriture qui échoue renvoie
 * false sans rien signaler. Une erreur de clé ou de bucket passe donc inaperçue
 * au dépôt et ne se manifeste qu'au téléchargement, longtemps après, sous la
 * forme d'une erreur incompréhensible pour l'exploitant.
 *
 * Cette commande écrit, relit et efface un objet témoin, puis vérifie que les
 * documents déjà enregistrés existent bien. Elle répond à la seule question qui
 * compte au déploiement : le stockage est-il réellement joignable ?
 */
class StorageCheck extends Command
{
    protected $signature = 'silaris:storage-check {--sample=20 : Nombre de documents récents à vérifier}';

    protected $description = "Vérifie que l'espace de stockage des documents est joignable et que les fichiers enregistrés existent.";

    public function handle(): int
    {
        $name = (string) config('filesystems.documents_disk', 'local');
        $this->line("Disque : <options=bold>{$name}</>");

        if ($name === 's3') {
            $bucket = config('filesystems.disks.s3.bucket');
            $this->line('  bucket   : '.($bucket ?: '<fg=red>non renseigné</>'));
            $this->line('  endpoint : '.(config('filesystems.disks.s3.endpoint') ?: '<fg=yellow>par défaut AWS</>'));
            $this->line('  région   : '.(config('filesystems.disks.s3.region') ?: '<fg=red>non renseignée</>'));
            $this->line('  clé      : '.(config('filesystems.disks.s3.key') ? 'renseignée' : '<fg=red>absente</>'));
        }

        $disk = Storage::disk($name);
        $key = 'diagnostics/storage-check-'.Str::random(16).'.txt';
        $payload = 'silaris storage check';

        try {
            if ($disk->put($key, $payload) === false) {
                $this->error("Écriture refusée. Le disque n'accepte pas d'objet : vérifiez les identifiants et le bucket.");

                return self::FAILURE;
            }

            $read = $disk->get($key);
            $disk->delete($key);

            if ($read !== $payload) {
                $this->error('Objet écrit mais relu différent — le stockage ne rend pas ce qu\'il reçoit.');

                return self::FAILURE;
            }
        } catch (Throwable $e) {
            $this->error('Stockage injoignable : '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Écriture, lecture et suppression : OK.');

        return $this->checkExistingDocuments($disk, (int) $this->option('sample'));
    }

    /**
     * Les objets manquants sont la trace d'un dépôt qui a échoué en silence, ou
     * d'un stockage éphémère perdu au redémarrage. Les nommer permet de savoir
     * quels documents redemander.
     */
    private function checkExistingDocuments(Filesystem $disk, int $sample): int
    {
        if ($sample <= 0) {
            return self::SUCCESS;
        }

        $versions = DocumentVersionModel::on(config('database.system_connection'))
            ->withoutGlobalScopes()
            ->orderByDesc('created_at')
            ->limit($sample)
            ->get(['id', 's3_key', 'original_filename']);

        if ($versions->isEmpty()) {
            $this->line('Aucun document enregistré à vérifier.');

            return self::SUCCESS;
        }

        $missing = $versions->reject(fn ($version) => $disk->exists((string) $version->s3_key));

        if ($missing->isEmpty()) {
            $this->info("Les {$versions->count()} documents les plus récents sont tous présents.");

            return self::SUCCESS;
        }

        $this->error("{$missing->count()} document(s) sur {$versions->count()} manquent sur le disque :");
        foreach ($missing as $version) {
            $this->line("  - {$version->original_filename} ({$version->s3_key})");
        }

        return self::FAILURE;
    }
}
