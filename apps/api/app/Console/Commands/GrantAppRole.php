<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rattache le rôle de connexion applicatif (non-propriétaire) au rôle silaris_app
 * créé par la migration RLS, pour qu'il hérite des droits + soit soumis à la RLS.
 * No-op si le rôle de connexion n'existe pas (dev/test en superuser).
 */
class GrantAppRole extends Command
{
    protected $signature = 'identity:grant-app-role {login : rôle de connexion à rattacher}';

    protected $description = 'GRANT silaris_app TO <login> (idempotent, no-op si le rôle est absent)';

    public function handle(): int
    {
        $login = $this->argument('login');
        $exists = DB::selectOne('SELECT 1 FROM pg_roles WHERE rolname = ?', [$login]) !== null;

        if (! $exists) {
            $this->info("Rôle {$login} absent — rien à faire.");

            return self::SUCCESS;
        }

        DB::statement(sprintf('GRANT silaris_app TO %s', $login));
        DB::statement(sprintf('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO %s', $login));
        DB::statement(sprintf('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO %s', $login));
        $this->info("silaris_app + droits schéma accordés à {$login}.");

        return self::SUCCESS;
    }
}
