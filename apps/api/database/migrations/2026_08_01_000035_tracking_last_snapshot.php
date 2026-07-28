<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instantané du dernier relevé transporteur.
 *
 * Les agrégateurs ne renvoient pas d'historique de mouvements mais une photo :
 * navire courant, dernière escale, prochaine escale, ETA. Réduire cette photo à
 * un seul événement de statut fait perdre l'essentiel de ce que la compagnie
 * sait — d'où sa conservation telle quelle, à afficher sur le dossier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_subscriptions', function (Blueprint $table): void {
            $table->jsonb('last_snapshot')->nullable()->comment('Dernier relevé transporteur, brut normalisé');
        });
    }

    public function down(): void
    {
        Schema::table('tracking_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('last_snapshot');
        });
    }
};
