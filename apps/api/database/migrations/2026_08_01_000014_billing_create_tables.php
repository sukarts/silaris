<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing — taxes (répliquées d'Odoo), factures, avoirs. AUCUNE écriture comptable.
 * Factures validées immuables (trigger) — Odoo est la source de vérité comptable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->decimal('rate_percent', 6, 3);
            $table->integer('odoo_id')->nullable()->comment('account.tax — source de vérité Odoo');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id')->comment('La séquence légale est par société');
            $table->string('type');
            $table->string('number', 32)->nullable()->comment('NULL en brouillon, attribué à la validation');
            $table->uuid('party_id');
            $table->uuid('shipment_id')->nullable();
            $table->uuid('quote_id')->nullable();
            $table->uuid('original_invoice_id')->nullable()->comment('Avoir → facture d\'origine');
            $table->string('status')->default('draft');
            $table->string('payment_status')->default('none')->comment('Écrit uniquement par OdooSync');
            $table->char('currency_code', 3);
            $table->decimal('total_excl_tax', 14, 2)->default(0);
            $table->decimal('total_tax', 14, 2)->default(0);
            $table->decimal('total_incl_tax', 14, 2)->default(0);
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestampTz('validated_at')->nullable();
            $table->uuid('validated_by')->nullable();
            $table->integer('odoo_id')->nullable()->comment('account.move');
            $table->string('credit_reason')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('party_id')->references('id')->on('parties');
            $table->foreign('shipment_id')->references('id')->on('shipments');
            $table->foreign('quote_id')->references('id')->on('quotes');
            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->foreign('validated_by')->references('id')->on('users');
            $table->index(['tenant_id', 'party_id', 'payment_status'], 'ix_invoices_tenant_party_status');
            $table->index(['tenant_id', 'status'], 'ix_invoices_tenant_status');
            $table->index('shipment_id', 'ix_invoices_shipment');
        });
        // FK auto-référente ajoutée après création (la PK doit exister avant la FK).
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreign('original_invoice_id')->references('id')->on('invoices');
        });
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT ck_invoices_type CHECK (type IN ('proforma','invoice','credit_note'))");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT ck_invoices_status CHECK (status IN ('draft','validated','synced','sync_failed'))");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT ck_invoices_payment_status CHECK (payment_status IN ('none','unpaid','partial','paid'))");
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT ck_invoices_totals CHECK (total_incl_tax = total_excl_tax + total_tax)');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT ck_invoices_credit_origin CHECK (type <> 'credit_note' OR original_invoice_id IS NOT NULL)");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT ck_invoices_credit_reason CHECK (type <> 'credit_note' OR credit_reason IS NOT NULL)");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT ck_invoices_validated_number CHECK (status = 'draft' OR number IS NOT NULL)");
        DB::statement('CREATE UNIQUE INDEX ux_invoices_company_type_number ON invoices (company_id, type, number) WHERE number IS NOT NULL');

        // Immuabilité après validation : seuls status, payment_status, odoo_id, updated_at restent modifiables.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_validated_invoice() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status <> 'draft' THEN
        IF NEW.number IS DISTINCT FROM OLD.number
            OR NEW.type IS DISTINCT FROM OLD.type
            OR NEW.party_id IS DISTINCT FROM OLD.party_id
            OR NEW.company_id IS DISTINCT FROM OLD.company_id
            OR NEW.currency_code IS DISTINCT FROM OLD.currency_code
            OR NEW.total_excl_tax IS DISTINCT FROM OLD.total_excl_tax
            OR NEW.total_tax IS DISTINCT FROM OLD.total_tax
            OR NEW.total_incl_tax IS DISTINCT FROM OLD.total_incl_tax
            OR NEW.issue_date IS DISTINCT FROM OLD.issue_date
            OR NEW.validated_at IS DISTINCT FROM OLD.validated_at THEN
            RAISE EXCEPTION 'Facture % validée : contenu immuable (créer un avoir)', OLD.number;
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER trg_invoices_immutable
    BEFORE UPDATE ON invoices
    FOR EACH ROW EXECUTE FUNCTION protect_validated_invoice();
CREATE OR REPLACE FUNCTION forbid_validated_invoice_delete() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status <> 'draft' THEN
        RAISE EXCEPTION 'Facture % validée : suppression interdite', OLD.number;
    END IF;
    RETURN OLD;
END;
$$;
CREATE TRIGGER trg_invoices_no_delete
    BEFORE DELETE ON invoices
    FOR EACH ROW EXECUTE FUNCTION forbid_validated_invoice_delete();
SQL);

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id');
            $table->unsignedInteger('position');
            $table->string('service_code');
            $table->string('description');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->string('unit');
            $table->decimal('unit_price', 14, 4);
            $table->uuid('tax_rate_id')->nullable();
            $table->decimal('line_total', 14, 2)->storedAs('round(quantity * unit_price, 2)');
            $table->timestampsTz();
            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
            $table->foreign('tax_rate_id')->references('id')->on('tax_rates');
            $table->index('invoice_id', 'ix_invoice_lines_invoice');
        });
        DB::statement("ALTER TABLE invoice_lines ADD CONSTRAINT ck_invoice_lines_unit CHECK (unit IN ('container','kg','m3','wm','flat','percent','unit'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_invoices_no_delete ON invoices; DROP TRIGGER IF EXISTS trg_invoices_immutable ON invoices; DROP FUNCTION IF EXISTS forbid_validated_invoice_delete(); DROP FUNCTION IF EXISTS protect_validated_invoice()');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('tax_rates');
    }
};
