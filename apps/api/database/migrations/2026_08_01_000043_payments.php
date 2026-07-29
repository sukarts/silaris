<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Règlements clients.
 *
 * Jusqu'ici l'état de paiement d'une facture venait d'Odoo : la colonne
 * `invoices.payment_status` portait la mention « écrit uniquement par
 * OdooSync ». Une facture validée dans SILARIS y restait donc sans suivi tant
 * qu'une comptabilité tierce ne le renvoyait pas — et sans cette comptabilité,
 * jamais.
 *
 * Un encaissement est rarement le miroir d'une facture : un client règle
 * plusieurs factures d'un virement, ou une seule en deux fois. D'où deux
 * tables — le règlement tel qu'il a été reçu, et son imputation facture par
 * facture. L'état de paiement se déduit alors des imputations au lieu d'être
 * déclaré, ce qui interdit qu'il contredise les montants.
 *
 * Un règlement encaissé ne se supprime pas : il s'annule, en gardant le motif.
 * La trace de ce qui est entré en caisse survit à l'erreur de saisie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id')->comment('Société encaissante — la séquence des reçus lui est propre');
            $table->uuid('party_id')->comment('Client qui règle');
            $table->string('reference', 32)->comment('Numéro de reçu, séquencé par société');
            $table->string('method', 24);
            $table->string('method_reference', 120)->nullable()
                ->comment('N° de chèque, référence de virement ou de transaction mobile money');
            $table->char('currency_code', 3);
            $table->decimal('amount', 14, 2);
            $table->date('received_on');
            $table->string('note', 500)->nullable();
            $table->uuid('recorded_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('party_id')->references('id')->on('parties');
            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->foreign('recorded_by')->references('id')->on('users');
            $table->foreign('cancelled_by')->references('id')->on('users');
            $table->index(['tenant_id', 'party_id', 'received_on'], 'ix_payments_tenant_party_date');
            $table->unique(['company_id', 'reference'], 'ux_payments_company_reference');
        });

        // Les moyens retenus sont ceux qu'un transitaire ivoirien rencontre :
        // le mobile money y règle autant de factures que le virement.
        DB::statement(<<<'SQL'
ALTER TABLE payments ADD CONSTRAINT ck_payments_method
    CHECK (method IN ('cash', 'transfer', 'cheque', 'mobile_money', 'card', 'compensation'))
SQL);
        DB::statement('ALTER TABLE payments ADD CONSTRAINT ck_payments_amount CHECK (amount > 0)');
        DB::statement(<<<'SQL'
ALTER TABLE payments ADD CONSTRAINT ck_payments_cancel
    CHECK ((cancelled_at IS NULL) = (cancel_reason IS NULL))
SQL);

        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('payment_id');
            $table->uuid('invoice_id');
            $table->decimal('amount', 14, 2);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('payment_id')->references('id')->on('payments')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices');
            // Un règlement n'impute qu'une fois la même facture : deux lignes
            // pour une même paire masqueraient un double comptage.
            $table->unique(['payment_id', 'invoice_id'], 'ux_payment_allocations_pair');
            $table->index('invoice_id', 'ix_payment_allocations_invoice');
        });

        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT ck_payment_allocations_amount CHECK (amount > 0)');

        DB::unprepared(<<<'SQL'
ALTER TABLE payments ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON payments
    USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid OR tenant_id IS NULL)
    WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
ALTER TABLE payment_allocations ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON payment_allocations
    USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid OR tenant_id IS NULL)
    WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
SQL);

        // L'état de paiement cesse d'être une donnée importée pour devenir la
        // lecture des imputations. Le commentaire le dit, faute de quoi le
        // prochain lecteur du schéma croira encore qu'Odoo en est la source.
        DB::statement(<<<'SQL'
COMMENT ON COLUMN invoices.payment_status IS
    'Déduit des imputations de règlement (voir payment_allocations), jamais saisi'
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        DB::statement("COMMENT ON COLUMN invoices.payment_status IS 'Écrit uniquement par OdooSync'");
    }
};
