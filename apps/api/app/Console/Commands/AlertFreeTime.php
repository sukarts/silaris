<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Silaris\Modules\Notifications\Application\Service\NotificationDispatcher;
use Silaris\Modules\Ocean\Application\Service\FreeTimeTracker;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;

/**
 * Alerte sur les franchises qui expirent.
 *
 * Une surestarie se prévient, elle ne se constate pas : passé l'échéance, la
 * compagnie facture chaque jour et il n'y a plus rien à négocier. L'alerte part
 * donc avant — trois jours, le temps d'organiser un enlèvement — puis le jour
 * même, puis une fois le dépassement constaté.
 *
 * L'exploitant du dossier et le client sont prévenus des mêmes échéances : le
 * premier peut agir, le second doit savoir ce qu'il devra payer.
 */
class AlertFreeTime extends Command
{
    protected $signature = 'demurrage:alert {--days=3 : Préavis en jours}';

    protected $description = 'Prévient des franchises conteneur qui arrivent à échéance.';

    public function handle(NotificationDispatcher $dispatcher, TenantContext $tenant): int
    {
        $notice = (int) $this->option('days');
        $today = Carbon::today();
        $sent = 0;

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $tenant->set((string) $tenantId);

            foreach (FreeTimeTracker::KINDS as $kind) {
                $endsAt = "ca.{$kind}_ends_at";

                $rows = DB::table('container_assignments AS ca')
                    ->join('containers AS c', 'c.id', '=', 'ca.container_id')
                    ->join('shipments AS s', 's.id', '=', 'ca.shipment_id')
                    ->whereNotNull($endsAt)
                    ->where(fn ($query) => $query
                        ->where(fn ($import) => $import->where('s.direction', '<>', 'export')
                            ->whereNull('ca.'.FreeTimeTracker::stopColumn($kind, 'import')))
                        ->orWhere(fn ($export) => $export->where('s.direction', 'export')
                            ->whereNull('ca.'.FreeTimeTracker::stopColumn($kind, 'export'))))
                    ->where($endsAt, '<=', $today->copy()->addDays($notice)->endOfDay())
                    ->get([
                        'ca.id', "{$endsAt} AS ends_at", 'c.number AS container_number',
                        's.id AS shipment_id', 's.reference', 's.client_id',
                    ]);

                foreach ($rows as $row) {
                    $deadline = Carbon::parse($row->ends_at)->startOfDay();
                    $remaining = (int) $today->diffInDays($deadline, false);

                    // Une seule alerte par compteur et par palier : sans cela, un
                    // conteneur en dépassement en enverrait une chaque jour, et
                    // plus personne ne les lirait.
                    $stage = $remaining < 0 ? 'overdue' : ($remaining === 0 ? 'today' : 'notice');
                    $marker = "{$kind}:{$row->id}:{$stage}";
                    $alreadyAlerted = DB::table('shipment_events')
                        ->where('tenant_id', $tenantId)
                        ->where('type', 'system')
                        ->whereRaw("payload->>'marker' = ?", [$marker])
                        ->exists();

                    if ($alreadyAlerted) {
                        continue;
                    }

                    $variables = [
                        'reference' => $row->reference,
                        'container_number' => $row->container_number,
                        'free_time_ends_at' => $deadline->format('d/m/Y'),
                        'days_remaining' => $remaining,
                    ];

                    $dispatcher->dispatchToClient(
                        (string) $tenantId, "{$kind}_warning", $row->shipment_id, $row->client_id, $variables,
                    );
                    $this->recordInternal((string) $tenantId, $kind, $row, $variables, $marker);

                    $sent++;
                }
            }
        }

        $this->info("{$sent} alerte(s) de franchise émise(s).");

        return self::SUCCESS;
    }

    /**
     * Trace l'alerte côté dossier : l'exploitant la voit dans la timeline sans
     * dépendre de sa boîte mail.
     *
     * @param  array<string, string|int>  $variables
     */
    private function recordInternal(string $tenantId, string $kind, object $row, array $variables, string $marker): void
    {
        $label = $kind === 'demurrage' ? 'Surestaries' : 'Détention';
        $late = $variables['days_remaining'] < 0;
        $title = $late
            ? "{$label} en cours — {$row->container_number} ({$variables['days_remaining']} j)"
            : "{$label} — franchise expire le {$variables['free_time_ends_at']} — {$row->container_number}";

        DB::table('shipment_events')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'shipment_id' => $row->shipment_id,
            'type' => 'system',
            'title' => $title,
            // Le marqueur sert de garde d'idempotence : il empêche une même
            // échéance d'alerter deux fois.
            'payload' => json_encode([...$variables, 'marker' => $marker]),
            'source' => 'system',
            'occurred_at' => now(),
        ]);
    }
}
