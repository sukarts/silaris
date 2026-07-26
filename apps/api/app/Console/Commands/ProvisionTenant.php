<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Onboarding d'un tenant en production : crée tenant + société + agence + admin
 * et rattache le rôle système « admin ». Outil super-admin (remplace tinker).
 *
 * RLS FORCE : companies/branches/users sont soumis aux policies même pour le
 * propriétaire. On positionne app.tenant_id (transaction-local) avant les
 * insertions pour satisfaire le WITH CHECK — fonctionne aussi bien avec l'app
 * propriétaire (MVP) qu'avec un rôle applicatif dédié.
 *
 * Le mot de passe admin est généré (fort) et affiché UNE fois si non fourni.
 */
final class ProvisionTenant extends Command
{
    protected $signature = 'identity:provision-tenant
        {--name= : Nom du tenant (ex. "Acme Freight")}
        {--slug= : Slug sous-domaine, [a-z0-9-] (ex. acme)}
        {--admin-email= : Email de l\'administrateur}
        {--admin-first= : Prénom admin}
        {--admin-last= : Nom admin}
        {--company= : Raison sociale (défaut : nom du tenant)}
        {--code= : Code société court (défaut : dérivé du slug)}
        {--currency=XOF : Devise ISO 4217}
        {--locale=fr : Locale par défaut}
        {--branch-code=HQ : Code de l\'agence principale}
        {--branch-name= : Nom de l\'agence (défaut : "Siège")}
        {--timezone=UTC : Fuseau de l\'agence (ex. Africa/Abidjan)}
        {--password= : Mot de passe admin (sinon généré)}';

    protected $description = 'Provisionne un nouveau tenant + société + agence + administrateur (onboarding prod).';

    private static function unambiguousPassword(int $length = 16): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $password;
    }

    public function handle(): int
    {
        $name = (string) ($this->option('name') ?: $this->ask('Nom du tenant'));
        $slug = Str::lower((string) ($this->option('slug') ?: $this->ask('Slug (sous-domaine)')));
        $email = Str::lower((string) ($this->option('admin-email') ?: $this->ask('Email admin')));
        $first = (string) ($this->option('admin-first') ?: $this->ask('Prénom admin'));
        $last = (string) ($this->option('admin-last') ?: $this->ask('Nom admin'));

        $company = (string) ($this->option('company') ?: $name);
        $code = Str::upper((string) ($this->option('code') ?: Str::substr(Str::slug($slug, ''), 0, 8)));
        $currency = Str::upper((string) $this->option('currency'));
        $locale = (string) $this->option('locale');
        $branchCode = Str::upper((string) $this->option('branch-code'));
        $branchName = (string) ($this->option('branch-name') ?: 'Siège');
        $timezone = (string) $this->option('timezone');
        // Mot de passe sans caractères ambigus (1/l/I, 0/O/o, symboles piégeux) :
        // il est recopié depuis un terminal — leçon du déploiement MVP.
        $password = (string) ($this->option('password') ?: self::unambiguousPassword());

        // Validations défensives -------------------------------------------------
        if ($name === '' || $slug === '' || $email === '' || $first === '' || $last === '') {
            $this->error('Nom, slug, email, prénom et nom sont obligatoires.');

            return self::INVALID;
        }
        if (! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $slug)) {
            $this->error('Slug invalide : minuscules, chiffres et tirets uniquement (ex. acme-ci).');

            return self::INVALID;
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Email admin invalide.');

            return self::INVALID;
        }

        // Connexion système : cohérente avec le durcissement RLS (voir config/database).
        $conn = DB::connection(config('database.system_connection', config('database.default')));

        if ($conn->table('tenants')->where('slug', $slug)->exists()) {
            $this->error("Le slug « {$slug} » est déjà pris.");

            return self::FAILURE;
        }

        $adminRoleId = $conn->table('roles')->whereNull('tenant_id')->where('key', 'admin')->value('id');
        if ($adminRoleId === null) {
            $this->error("Rôle système « admin » introuvable. Lance d'abord : php artisan db:seed --force");

            return self::FAILURE;
        }

        $now = now();
        $tenantId = (string) Str::uuid();
        $companyId = (string) Str::uuid();
        $branchId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        try {
            $conn->transaction(function () use (
                $conn, $tenantId, $companyId, $branchId, $userId, $adminRoleId, $now,
                $name, $slug, $company, $code, $currency, $locale,
                $branchCode, $branchName, $timezone, $email, $first, $last, $password
            ): void {
                // RLS WITH CHECK : app.tenant_id local à la transaction (parametré).
                $conn->statement("SELECT set_config('app.tenant_id', ?, true)", [$tenantId]);

                $conn->table('tenants')->insert([
                    'id' => $tenantId, 'name' => $name, 'slug' => $slug,
                    'plan' => 'standard', 'locale_default' => $locale,
                    'settings' => json_encode(['tracking_refresh_minutes' => 1440, 'delay_threshold_hours' => 24]),
                    'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);

                $conn->table('companies')->insert([
                    'id' => $companyId, 'tenant_id' => $tenantId,
                    'legal_name' => $company, 'code' => $code,
                    'tax_id' => null, 'currency_code' => $currency,
                    'address' => json_encode(new \stdClass),
                    'invoice_settings' => json_encode(['number_format' => 'F-{YEAR}-{SEQ:4}']),
                    'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);

                $conn->table('branches')->insert([
                    'id' => $branchId, 'tenant_id' => $tenantId, 'company_id' => $companyId,
                    'name' => $branchName, 'code' => $branchCode, 'timezone' => $timezone,
                    'address' => json_encode(new \stdClass),
                    'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);

                $conn->table('users')->insert([
                    'id' => $userId, 'tenant_id' => $tenantId,
                    'email' => $email, 'password_hash' => Hash::make($password),
                    'first_name' => $first, 'last_name' => $last,
                    'locale' => $locale, 'is_active' => true,
                    'password_changed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);

                // user_roles / user_branches : pas de tenant_id (contraints via user_id).
                $conn->table('user_roles')->insert(['user_id' => $userId, 'role_id' => $adminRoleId]);
                $conn->table('user_branches')->insert(['user_id' => $userId, 'branch_id' => $branchId]);
            });
        } catch (\Throwable $e) {
            $this->error('Échec du provisioning : '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Tenant provisionné.');
        $this->table(['Champ', 'Valeur'], [
            ['Tenant', "{$name} ({$slug})"],
            ['Tenant ID', $tenantId],
            ['Société', "{$company} [{$code}] — {$currency}"],
            ['Agence', "{$branchName} [{$branchCode}] — {$timezone}"],
            ['Admin', "{$first} {$last} <{$email}>"],
            ['Rôle', 'admin (système)'],
        ]);
        $this->newLine();
        $this->warn('Mot de passe admin (à transmettre de façon sûre, affiché une seule fois) :');
        $this->line("    {$password}");
        $this->newLine();
        $this->comment("Connexion : en-tête X-Tenant-Slug: {$slug} (ou sous-domaine {$slug}.<domaine>), email {$email}.");

        return self::SUCCESS;
    }
}
