<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRM — parties unifiées (clients/prospects/fournisseurs), contacts, opportunités, réclamations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('type');
            $table->string('supplier_kind')->nullable();
            $table->string('code', 24);
            $table->string('name');
            $table->string('tax_id')->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->decimal('credit_limit', 14, 2)->nullable();
            $table->jsonb('notification_prefs')->default('{}');
            $table->jsonb('tags')->default('[]');
            $table->uuid('owner_id')->nullable()->comment('Commercial référent');
            $table->integer('odoo_id')->nullable();
            $table->timestampTz('converted_from_prospect_at')->nullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->foreign('owner_id')->references('id')->on('users');
            $table->unique(['tenant_id', 'code'], 'ux_parties_tenant_code');
            $table->index(['tenant_id', 'type'], 'ix_parties_tenant_type');
            $table->index(['tenant_id', 'name'], 'ix_parties_tenant_name');
        });
        DB::statement("ALTER TABLE parties ADD CONSTRAINT ck_parties_type CHECK (type IN ('client','prospect','supplier'))");
        DB::statement("ALTER TABLE parties ADD CONSTRAINT ck_parties_supplier_kind CHECK (supplier_kind IS NULL OR supplier_kind IN ('ocean_carrier','airline','trucker','customs_agent','handler','insurer','port_agent','overseas_agent'))");
        DB::statement("ALTER TABLE parties ADD CONSTRAINT ck_parties_supplier_has_kind CHECK (type <> 'supplier' OR supplier_kind IS NOT NULL)");

        Schema::create('party_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('party_id');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('role')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('party_id')->references('id')->on('parties')->cascadeOnDelete();
            $table->index('party_id', 'ix_party_contacts_party');
        });

        Schema::create('party_addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('party_id');
            $table->string('label')->default('main');
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('city');
            $table->string('postal_code')->nullable();
            $table->char('country_code', 2);
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('party_id')->references('id')->on('parties')->cascadeOnDelete();
            $table->foreign('country_code')->references('code2')->on('countries');
            $table->index('party_id', 'ix_party_addresses_party');
        });

        Schema::create('opportunities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('party_id');
            $table->string('title');
            $table->string('stage')->default('new');
            $table->decimal('estimated_value', 14, 2)->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->unsignedTinyInteger('probability')->nullable();
            $table->uuid('owner_id');
            $table->date('expected_close_date')->nullable();
            $table->string('lost_reason')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('party_id')->references('id')->on('parties');
            $table->foreign('owner_id')->references('id')->on('users');
            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->index(['tenant_id', 'stage'], 'ix_opportunities_tenant_stage');
        });
        DB::statement("ALTER TABLE opportunities ADD CONSTRAINT ck_opportunities_stage CHECK (stage IN ('new','qualified','proposal','negotiation','won','lost'))");
        DB::statement('ALTER TABLE opportunities ADD CONSTRAINT ck_opportunities_probability CHECK (probability IS NULL OR probability <= 100)');

        Schema::create('complaints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('party_id');
            $table->uuid('shipment_id')->nullable()->comment('FK ajoutée après création de shipments');
            $table->string('reference', 32);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('severity')->default('medium');
            $table->string('status')->default('open');
            $table->uuid('assignee_id')->nullable();
            $table->timestampTz('sla_due_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution')->nullable();
            $table->decimal('cost', 14, 2)->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('party_id')->references('id')->on('parties');
            $table->foreign('assignee_id')->references('id')->on('users');
            $table->unique(['tenant_id', 'reference'], 'ux_complaints_tenant_reference');
            $table->index(['tenant_id', 'status'], 'ix_complaints_tenant_status');
        });
        DB::statement("ALTER TABLE complaints ADD CONSTRAINT ck_complaints_severity CHECK (severity IN ('low','medium','high','critical'))");
        DB::statement("ALTER TABLE complaints ADD CONSTRAINT ck_complaints_status CHECK (status IN ('open','investigating','resolved','closed','rejected'))");

        Schema::create('portal_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('party_id');
            $table->string('email');
            $table->string('password_hash');
            $table->string('name');
            $table->jsonb('notification_prefs')->default('{}')->comment('Matrice canal x événement, surcharge parties.notification_prefs');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('party_id')->references('id')->on('parties');
        });
        DB::statement('CREATE UNIQUE INDEX ux_portal_accounts_tenant_email ON portal_accounts (tenant_id, lower(email))');
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_accounts');
        Schema::dropIfExists('complaints');
        Schema::dropIfExists('opportunities');
        Schema::dropIfExists('party_addresses');
        Schema::dropIfExists('party_contacts');
        Schema::dropIfExists('parties');
    }
};
