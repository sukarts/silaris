<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications — templates multilingues, préférences canal × événement, envois tracés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('event_type');
            $table->string('channel');
            $table->string('locale', 5)->default('fr');
            $table->string('subject')->nullable()->comment('Email uniquement');
            $table->text('body')->comment('Variables : {{shipment.reference}}, {{eta}}…');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['tenant_id', 'event_type', 'channel', 'locale'], 'ux_notification_templates');
        });
        DB::statement("ALTER TABLE notification_templates ADD CONSTRAINT ck_templates_channel CHECK (channel IN ('email','sms','whatsapp','in_app'))");
        DB::statement("ALTER TABLE notification_templates ADD CONSTRAINT ck_templates_event CHECK (event_type IN ('departure','arrival','customs','delay','delivery','document_missing','invoice_available','quote_sent','custom'))");

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id')->nullable();
            $table->uuid('portal_account_id')->nullable();
            $table->string('event_type');
            $table->string('channel');
            $table->boolean('enabled')->default(true);
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('portal_account_id')->references('id')->on('portal_accounts')->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE notification_preferences ADD CONSTRAINT ck_notif_prefs_target CHECK ((user_id IS NULL) <> (portal_account_id IS NULL))');
        DB::statement("CREATE UNIQUE INDEX ux_notif_prefs ON notification_preferences (tenant_id, COALESCE(user_id, '00000000-0000-0000-0000-000000000000'::uuid), COALESCE(portal_account_id, '00000000-0000-0000-0000-000000000000'::uuid), event_type, channel)");

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('event_type');
            $table->uuid('shipment_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->uuid('portal_account_id')->nullable();
            $table->string('title');
            $table->text('body')->nullable();
            $table->jsonb('payload')->default('{}');
            $table->timestampTz('read_at')->nullable()->comment('In-app uniquement');
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('shipment_id')->references('id')->on('shipments');
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('portal_account_id')->references('id')->on('portal_accounts');
            $table->index(['tenant_id', 'user_id', 'read_at'], 'ix_notifications_user_unread');
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('notification_id');
            $table->string('channel');
            $table->string('recipient')->comment('email | téléphone E.164 | user id');
            $table->string('status')->default('queued');
            $table->string('provider_message_id')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('notification_id')->references('id')->on('notifications')->cascadeOnDelete();
            $table->index(['tenant_id', 'status'], 'ix_notif_deliveries_status');
        });
        DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT ck_deliveries_channel CHECK (channel IN ('email','sms','whatsapp','in_app'))");
        DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT ck_deliveries_status CHECK (status IN ('queued','sent','delivered','failed','skipped'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_templates');
    }
};
