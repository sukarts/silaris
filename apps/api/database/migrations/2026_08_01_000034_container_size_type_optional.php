<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le type d'un conteneur n'est pas toujours connu à sa découverte.
 *
 * À l'import, le transitaire part d'un connaissement : la compagnie lui répond
 * les numéros de conteneurs rattachés, sans toujours leur gabarit. Exiger le
 * type interdirait de les enregistrer — alors que le numéro suffit à les
 * suivre, et que le type se complète ensuite.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE containers ALTER COLUMN size_type DROP NOT NULL');
        DB::statement('ALTER TABLE containers DROP CONSTRAINT ck_containers_size_type');
        DB::statement(<<<'SQL'
ALTER TABLE containers ADD CONSTRAINT ck_containers_size_type
    CHECK (size_type IS NULL OR size_type IN
        ('20GP','40GP','40HC','45HC','20RF','40RF','20OT','40OT','20FR','40FR','20TK'))
SQL);
    }

    public function down(): void
    {
        DB::statement("UPDATE containers SET size_type = '40GP' WHERE size_type IS NULL");
        DB::statement('ALTER TABLE containers DROP CONSTRAINT ck_containers_size_type');
        DB::statement(<<<'SQL'
ALTER TABLE containers ADD CONSTRAINT ck_containers_size_type
    CHECK (size_type IN ('20GP','40GP','40HC','45HC','20RF','40RF','20OT','40OT','20FR','40FR','20TK'))
SQL);
        DB::statement('ALTER TABLE containers ALTER COLUMN size_type SET NOT NULL');
    }
};
