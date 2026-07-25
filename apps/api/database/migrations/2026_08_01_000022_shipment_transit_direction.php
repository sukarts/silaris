<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sens « transit » : dossier de transbordement / réexpédition — la marchandise
 * traverse le pays du transitaire sans y être importée ni exportée
 * (ex. arrivée Abidjan → réexpédition Bamako sous régime de transit douanier).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE shipments DROP CONSTRAINT ck_shipments_direction');
        DB::statement("ALTER TABLE shipments ADD CONSTRAINT ck_shipments_direction CHECK (direction IN ('import','export','transit'))");
        DB::statement('ALTER TABLE quotes DROP CONSTRAINT ck_quotes_direction');
        DB::statement("ALTER TABLE quotes ADD CONSTRAINT ck_quotes_direction CHECK (direction IN ('import','export','transit'))");
        DB::statement('ALTER TABLE workflow_definitions DROP CONSTRAINT ck_wfd_direction');
        DB::statement("ALTER TABLE workflow_definitions ADD CONSTRAINT ck_wfd_direction CHECK (direction IN ('import','export','transit','any'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE workflow_definitions DROP CONSTRAINT ck_wfd_direction');
        DB::statement("ALTER TABLE workflow_definitions ADD CONSTRAINT ck_wfd_direction CHECK (direction IN ('import','export','any'))");
        DB::statement('ALTER TABLE quotes DROP CONSTRAINT ck_quotes_direction');
        DB::statement("ALTER TABLE quotes ADD CONSTRAINT ck_quotes_direction CHECK (direction IN ('import','export'))");
        DB::statement('ALTER TABLE shipments DROP CONSTRAINT ck_shipments_direction');
        DB::statement("ALTER TABLE shipments ADD CONSTRAINT ck_shipments_direction CHECK (direction IN ('import','export'))");
    }
};
