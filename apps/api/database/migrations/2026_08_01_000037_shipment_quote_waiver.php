<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dérogation à l'accord préalable du client.
 *
 * À l'import, la marchandise arrive parfois avant la cotation signée : un
 * connaissement tombe, le conteneur est au port, et l'exploitant doit ouvrir le
 * dossier sans attendre. Refuser bloquerait le terrain ; laisser passer viderait
 * la règle de son sens.
 *
 * L'exploitant soumet donc l'ouverture avec son motif, et le dossier reste en
 * attente jusqu'à décision de la direction. Tant qu'il n'est pas validé, il
 * n'avance pas dans le workflow — sans cette contrainte, « en attente » ne
 * serait qu'une étiquette.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->string('quote_waiver_status')->nullable()
                ->comment('pending|approved|rejected — nul quand le dossier repose sur une cotation');
            $table->text('quote_waiver_reason')->nullable()->comment('Motif invoqué par le demandeur');
            $table->uuid('quote_waiver_requested_by')->nullable();
            $table->timestampTz('quote_waiver_requested_at')->nullable();
            $table->uuid('quote_waiver_decided_by')->nullable();
            $table->timestampTz('quote_waiver_decided_at')->nullable();
            $table->text('quote_waiver_decision_note')->nullable();

            $table->foreign('quote_waiver_requested_by')->references('id')->on('users');
            $table->foreign('quote_waiver_decided_by')->references('id')->on('users');
            // La file d'attente de la direction se lit sur cet index.
            $table->index(['tenant_id', 'quote_waiver_status'], 'ix_shipments_waiver');
        });

        DB::statement(<<<'SQL'
ALTER TABLE shipments ADD CONSTRAINT ck_shipments_waiver_status
    CHECK (quote_waiver_status IS NULL OR quote_waiver_status IN ('pending','approved','rejected'))
SQL);

        // Un dossier sans cotation ni dérogation ne peut plus exister : les
        // dossiers déjà ouverts sont réputés validés, faute de quoi ils se
        // retrouveraient bloqués du jour au lendemain.
        DB::table('shipments')->whereNull('quote_id')->update([
            'quote_waiver_status' => 'approved',
            'quote_waiver_reason' => 'Dossier ouvert avant la mise en place de la règle.',
            'quote_waiver_decided_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE shipments DROP CONSTRAINT ck_shipments_waiver_status');
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropForeign(['quote_waiver_requested_by']);
            $table->dropForeign(['quote_waiver_decided_by']);
            $table->dropIndex('ix_shipments_waiver');
            $table->dropColumn([
                'quote_waiver_status', 'quote_waiver_reason', 'quote_waiver_requested_by',
                'quote_waiver_requested_at', 'quote_waiver_decided_by', 'quote_waiver_decided_at',
                'quote_waiver_decision_note',
            ]);
        });
    }
};
