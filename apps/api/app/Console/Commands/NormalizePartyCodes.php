<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Silaris\Modules\Crm\Application\Service\PartyCodeGenerator;

/**
 * Remet les codes tiers dans la nomenclature.
 *
 * Le code d'un tiers était saisissable à la création : des fiches sont donc
 * parties avec un code libre — « DAI », « D&F » — au lieu du CLI-0003 attendu.
 * Un code hors nomenclature ne se trie pas, ne se rapproche pas d'un relevé
 * comptable, et se confond avec le nom du client sur une facture.
 *
 * La commande ne touche qu'aux codes qui ne suivent pas la forme attendue, et
 * ne fait rien sans --apply : renommer un tiers change ce qui s'affiche sur ses
 * factures et cotations passées, cela se regarde avant de se décider.
 */
class NormalizePartyCodes extends Command
{
    protected $signature = 'silaris:normalize-party-codes {--apply : Écrit les changements ; sinon simulation}';

    protected $description = 'Réattribue un code conforme aux tiers dont le code a été saisi à la main.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $connection = config('database.system_connection');

        $parties = DB::connection($connection)
            ->table('parties')
            ->select(['id', 'tenant_id', 'type', 'code', 'name'])
            ->orderBy('created_at')
            ->get()
            ->reject(fn ($party) => PartyCodeGenerator::isConform((string) $party->code));

        if ($parties->isEmpty()) {
            $this->info('Tous les codes tiers suivent la nomenclature.');

            return self::SUCCESS;
        }

        $this->line($apply ? 'Réattribution :' : 'Simulation — relancez avec --apply pour écrire :');

        foreach ($parties as $party) {
            $code = PartyCodeGenerator::next((string) $party->tenant_id, (string) $party->type);

            $this->line(sprintf('  %-28s %s → %s', mb_strimwidth((string) $party->name, 0, 28, '…'), $party->code, $code));

            if ($apply) {
                DB::connection($connection)->table('parties')
                    ->where('id', $party->id)
                    ->update(['code' => $code, 'updated_at' => now()]);
            }
        }

        $this->newLine();
        $this->info($apply
            ? "{$parties->count()} code(s) réattribué(s)."
            : "{$parties->count()} code(s) à réattribuer.");

        return self::SUCCESS;
    }
}
