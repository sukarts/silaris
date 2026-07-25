<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Silaris\Modules\Notifications\Application\Service\OutboxProcessor;

/**
 * Worker outbox → notifications : consomme les événements non publiés et
 * envoie les emails clients (départ/arrivée/douane/livraison/retard/facture).
 * Planifié chaque minute (routes/console.php), sans chevauchement.
 */
final class ProcessOutbox extends Command
{
    protected $signature = 'outbox:process';

    protected $description = "Publie les événements de l'outbox et envoie les notifications associées.";

    public function handle(OutboxProcessor $processor): int
    {
        $stats = $processor->run();

        $this->info(sprintf(
            'Outbox : %d traité(s), %d notification(s) envoyée(s), %d échec(s).',
            $stats['processed'], $stats['notified'], $stats['failed'],
        ));

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
