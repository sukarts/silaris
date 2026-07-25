<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fonctions PostgreSQL utilitaires — validations métier au niveau moteur.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Validation ISO 6346 : 4 lettres + 6 chiffres + 1 chiffre de contrôle.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION iso6346_check(container_number text) RETURNS boolean
LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE
    upper_num text := upper(replace(container_number, ' ', ''));
    letter_values int[] := ARRAY[10,12,13,14,15,16,17,18,19,20,21,23,24,25,26,27,28,29,30,31,32,34,35,36,37,38];
    total numeric := 0;
    ch char;
    val int;
    i int;
BEGIN
    IF upper_num !~ '^[A-Z]{4}[0-9]{7}$' THEN
        RETURN false;
    END IF;
    FOR i IN 1..10 LOOP
        ch := substr(upper_num, i, 1);
        IF ch ~ '[A-Z]' THEN
            val := letter_values[ascii(ch) - 64];
        ELSE
            val := ch::int;
        END IF;
        total := total + val * power(2, i - 1);
    END LOOP;
    RETURN (total % 11) % 10 = substr(upper_num, 11, 1)::int;
END;
$$;
SQL);

        // Validation AWB : 11 chiffres, les 8 derniers = série(7) + contrôle (série mod 7).
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION awb_mod7(awb_number text) RETURNS boolean
LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE
    clean text := replace(replace(awb_number, ' ', ''), '-', '');
BEGIN
    IF clean !~ '^[0-9]{11}$' THEN
        RETURN false;
    END IF;
    RETURN substr(clean, 4, 7)::bigint % 7 = substr(clean, 11, 1)::int;
END;
$$;
SQL);

        // Numérotation sans trou — incrément atomique sous verrou ligne.
        // À appeler dans la MÊME transaction que l'INSERT métier.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION next_sequence(p_tenant_id uuid, p_scope text) RETURNS bigint
LANGUAGE plpgsql AS $$
DECLARE
    v_next bigint;
BEGIN
    INSERT INTO sequences (tenant_id, scope, last_value)
    VALUES (p_tenant_id, p_scope, 1)
    ON CONFLICT (tenant_id, scope)
    DO UPDATE SET last_value = sequences.last_value + 1
    RETURNING last_value INTO v_next;
    RETURN v_next;
END;
$$;
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS next_sequence(uuid, text)');
        DB::unprepared('DROP FUNCTION IF EXISTS awb_mod7(text)');
        DB::unprepared('DROP FUNCTION IF EXISTS iso6346_check(text)');
    }
};
