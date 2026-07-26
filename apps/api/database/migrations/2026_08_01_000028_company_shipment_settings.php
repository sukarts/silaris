<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réglages dossiers par société : format de référence choisi par le transitaire
 * (placeholders {PREFIX} {COMPANY} {BRANCH} {YEAR} {YY} {MONTH} {SEQ:n}).
 * Défaut = comportement historique {COMPANY}-{YEAR}-{SEQ:5}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->jsonb('shipment_settings')->default('{}')->after('invoice_settings');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('shipment_settings');
        });
    }
};
