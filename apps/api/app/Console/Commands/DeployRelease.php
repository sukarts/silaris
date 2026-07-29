<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\CustomsTariffSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Console\Command;

/**
 * Mise en service d'une version.
 *
 * Les migrations font évoluer le schéma, mais rôles et permissions vivent dans
 * un seeder que rien n'exécutait au déploiement : un rôle ajouté au code
 * n'apparaissait jamais en production, et les écrans qui en dépendent restaient
 * inertes sans que rien ne le signale.
 *
 * Les deux étapes sont donc réunies ici, l'hébergeur n'acceptant qu'une seule
 * commande de pré-déploiement. Le catalogue de permissions est réappliqué à
 * chaque version : le seeder est idempotent et ne touche qu'aux rôles système,
 * laissant intacts les rôles propres à chaque transitaire.
 */
class DeployRelease extends Command
{
    protected $signature = 'silaris:deploy';

    protected $description = 'Applique les migrations puis synchronise rôles et permissions.';

    public function handle(): int
    {
        $this->info('Migrations…');
        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            $this->error('Migrations en échec — déploiement interrompu.');

            return self::FAILURE;
        }

        $this->info('Rôles et permissions…');
        $this->call('db:seed', ['--class' => PermissionSeeder::class, '--force' => true]);

        $this->info('Tarif douanier…');
        $this->call('db:seed', ['--class' => CustomsTariffSeeder::class, '--force' => true]);

        return self::SUCCESS;
    }
}
