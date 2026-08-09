<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dépenses — factures fournisseurs rattachées à un dossier.
 *
 * Elles portent le coût réel du dossier, face au coût estimé de la cotation :
 * la différence entre marge prévisionnelle et marge réelle se lit là. Une
 * dépense annulée ne compte plus ; recorded / validated / paid tracent son
 * traitement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('shipment_id');
            $table->uuid('supplier_id')->nullable()->comment('Tiers fournisseur (parties), si connu');
            $table->string('service_code', 32)->nullable()->comment('Poste du catalogue, à titre indicatif');
            $table->string('label', 200);
            $table->decimal('amount', 14, 2);
            $table->char('currency_code', 3);
            $table->string('supplier_invoice_number', 64)->nullable();
            $table->date('invoice_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('status')->default('recorded');
            $table->string('note', 500)->nullable();
            $table->uuid('recorded_by')->nullable();
            $table->uuid('validated_by')->nullable();
            $table->timestampTz('validated_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('shipment_id')->references('id')->on('shipments')->cascadeOnDelete();
            $table->foreign('supplier_id')->references('id')->on('parties')->nullOnDelete();
            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->foreign('recorded_by')->references('id')->on('users');
            $table->foreign('validated_by')->references('id')->on('users');
            $table->index(['tenant_id', 'shipment_id'], 'ix_expenses_shipment');
            $table->index(['tenant_id', 'status'], 'ix_expenses_status');
        });
        DB::statement("ALTER TABLE expenses ADD CONSTRAINT ck_expenses_status CHECK (status IN ('recorded','validated','paid','cancelled'))");
        DB::statement('ALTER TABLE expenses ADD CONSTRAINT ck_expenses_amount CHECK (amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
