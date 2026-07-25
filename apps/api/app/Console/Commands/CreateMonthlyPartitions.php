<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateMonthlyPartitions extends Command
{
    protected $signature = 'db:create-partitions {--months=3 : Nombre de mois à préparer}';

    protected $description = 'Crée les partitions mensuelles à venir pour tracking_events et audit_logs';

    public function handle(): int
    {
        foreach (['tracking_events', 'audit_logs'] as $table) {
            for ($i = 0; $i <= (int) $this->option('months'); $i++) {
                $start = now()->startOfMonth()->addMonths($i);
                $end = $start->copy()->addMonth();
                $partition = "{$table}_{$start->format('Y_m')}";

                $exists = DB::selectOne('SELECT to_regclass(?) AS oid', [$partition])->oid !== null;
                if (! $exists) {
                    DB::unprepared(sprintf(
                        'CREATE TABLE %s PARTITION OF %s FOR VALUES FROM (%s) TO (%s)',
                        $partition,
                        $table,
                        "'".$start->toDateString()."'",
                        "'".$end->toDateString()."'",
                    ));
                    $this->info("créée : {$partition}");
                }
            }
        }

        return self::SUCCESS;
    }
}
