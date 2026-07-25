<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nature juridique de la partie : personne morale (société) ou personne physique.
 * Impacte l'affichage, la facturation (mentions) et la sync Odoo (is_company).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->string('kind', 16)->default('company')->after('type')
                ->comment('company = personne morale | individual = personne physique');
        });
        DB::statement("ALTER TABLE parties ADD CONSTRAINT ck_parties_kind CHECK (kind IN ('company','individual'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE parties DROP CONSTRAINT ck_parties_kind');
        Schema::table('parties', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
