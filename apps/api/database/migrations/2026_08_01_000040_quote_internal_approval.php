<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Validation interne d'une cotation avant transmission au client.
 *
 * Une cotation engage un prix : une fois partie, elle ne se reprend pas. Le
 * commercial la prépare, mais elle passe sous les yeux du directeur, d'un
 * administrateur ou du responsable commercial avant de quitter la maison.
 *
 * La validation est distincte de l'envoi : elle dit qui a engagé la société sur
 * ce prix, information qu'un simple horodatage d'envoi ne porterait pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->foreign('approved_by')->references('id')->on('users');
        });

        // Les cotations déjà transmises sont réputées validées : la règle
        // n'a pas d'effet rétroactif sur ce qui est parti chez le client.
        DB::table('quotes')->whereIn('status', ['sent', 'accepted', 'rejected', 'expired'])
            ->update(['approved_at' => DB::raw('COALESCE(sent_at, created_at)')]);
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['approved_by', 'approved_at']);
        });
    }
};
