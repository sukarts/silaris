<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Identity — utilisateurs, RBAC, tokens API, journal de sessions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('email');
            $table->string('password_hash');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone')->nullable()->comment('E.164');
            $table->text('mfa_secret')->nullable()->comment('Chiffré applicativement');
            $table->boolean('mfa_enabled')->default(false);
            $table->jsonb('mfa_recovery_codes')->nullable()->comment('Hashés');
            $table->string('locale', 5)->default('fr');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('password_changed_at')->useCurrent();
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });
        DB::statement('CREATE UNIQUE INDEX ux_users_tenant_email ON users (tenant_id, lower(email)) WHERE deleted_at IS NULL');

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->comment('NULL = rôle système commun à tous les tenants');
            $table->string('key');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });
        DB::statement("CREATE UNIQUE INDEX ux_roles_scope_key ON roles (COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000'::uuid), key)");

        Schema::create('permissions', function (Blueprint $table) {
            $table->string('key')->primary()->comment('ex: shipments.create');
            $table->string('module');
            $table->string('label');
            $table->timestampsTz();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->uuid('role_id');
            $table->string('permission_key');
            $table->primary(['role_id', 'permission_key']);
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('permission_key')->references('key')->on('permissions');
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->uuid('user_id');
            $table->uuid('role_id');
            $table->primary(['user_id', 'role_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
        });

        Schema::create('user_branches', function (Blueprint $table) {
            $table->uuid('user_id');
            $table->uuid('branch_id');
            $table->primary(['user_id', 'branch_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
        });

        // Sanctum — tokens personnels (schéma standard, PK bigint acceptée : table framework)
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->string('key_hash')->unique();
            $table->string('prefix', 12)->comment('Affichable : slrs_live_xxx');
            $table->jsonb('scopes')->default('[]');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->uuid('created_by');
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('created_by')->references('id')->on('users');
        });

        Schema::create('sessions_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->string('event')->comment('login|logout|login_failed|mfa_failed|token_revoked');
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users');
            $table->index(['tenant_id', 'user_id', 'occurred_at'], 'ix_sessions_log_user');
        });
        DB::statement("ALTER TABLE sessions_log ADD CONSTRAINT ck_sessions_log_event CHECK (event IN ('login','logout','login_failed','mfa_failed','token_revoked'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions_log');
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('user_branches');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('users');
    }
};
