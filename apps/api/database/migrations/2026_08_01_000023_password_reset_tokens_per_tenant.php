<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jetons de reset par tenant : l'email n'étant unique que par tenant, la clé
 * globale email autorisait l'écrasement/ciblage inter-tenant. Clé = (tenant_id, email).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->string('email');
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
            $table->primary(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });
    }
};
