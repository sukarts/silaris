<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenancy — hiérarchie Plateforme → Tenant → Société → Agence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('plan')->default('standard');
            $table->string('locale_default', 5)->default('fr');
            $table->jsonb('settings')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT ck_tenants_plan CHECK (plan IN ('trial','standard','enterprise','private'))");

        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('legal_name');
            $table->string('code', 16);
            $table->string('tax_id')->nullable();
            $table->char('currency_code', 3);
            $table->jsonb('address')->default('{}');
            $table->jsonb('invoice_settings')->default('{}')->comment('Mentions légales, formats de numérotation');
            $table->string('logo_document_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->unique(['tenant_id', 'code'], 'ux_companies_tenant_code');
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->string('name');
            $table->string('code', 8)->comment('Utilisé dans les références dossier');
            $table->string('timezone')->default('UTC');
            $table->jsonb('address')->default('{}');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('company_id')->references('id')->on('companies');
            $table->unique(['tenant_id', 'code'], 'ux_branches_tenant_code');
            $table->index('company_id', 'ix_branches_company');
        });

        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->string('key');
            $table->jsonb('value');
            $table->uuid('company_id')->nullable()->comment('Surcharge par société');
            $table->uuid('branch_id')->nullable()->comment('Surcharge par agence');
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('branch_id')->references('id')->on('branches');
        });
        DB::statement('CREATE UNIQUE INDEX ux_tenant_settings_scope ON tenant_settings (tenant_id, key, COALESCE(company_id, \'00000000-0000-0000-0000-000000000000\'::uuid), COALESCE(branch_id, \'00000000-0000-0000-0000-000000000000\'::uuid))');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('tenants');
    }
};
