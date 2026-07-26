<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une « agence » couvre deux réalités du transit : l'implantation propre du
 * transitaire, et le correspondant partenaire qui le représente dans un autre
 * pays. Les deux apparaissent dans les dossiers et les références, d'où la
 * même table — distinguées par `kind`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->string('kind')->default('own')->after('code')->comment('own|partner');
            $table->string('partner_name')->nullable()->after('kind')->comment('Raison sociale du correspondant');
            $table->char('country_code', 2)->nullable()->after('partner_name');
            $table->string('city')->nullable()->after('country_code');
            $table->char('locode', 5)->nullable()->after('city')->comment('UN/LOCODE de la ville, source du code agence');

            $table->foreign('country_code')->references('code2')->on('countries');
            $table->index(['tenant_id', 'kind'], 'ix_branches_kind');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropForeign(['country_code']);
            $table->dropIndex('ix_branches_kind');
            $table->dropColumn(['kind', 'partner_name', 'country_code', 'city', 'locode']);
        });
    }
};
