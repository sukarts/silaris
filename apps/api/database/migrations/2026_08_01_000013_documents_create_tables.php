<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Documents — GED avec versioning, visibilité, scan antivirus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('shipment_id')->nullable();
            $table->uuid('party_id')->nullable()->comment('Docs hors dossier : contrats client');
            $table->string('type');
            $table->string('title');
            $table->string('visibility')->default('internal');
            $table->string('status')->default('received')->comment('missing = attendu par checklist, pas encore fourni');
            $table->boolean('is_archived')->default(false);
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('shipment_id')->references('id')->on('shipments');
            $table->foreign('party_id')->references('id')->on('parties');
            $table->index(['tenant_id', 'shipment_id'], 'ix_documents_shipment');
            $table->index(['tenant_id', 'type', 'status'], 'ix_documents_type_status');
        });
        DB::statement("ALTER TABLE documents ADD CONSTRAINT ck_documents_type CHECK (type IN ('bl','hbl','mbl','awb','commercial_invoice','packing_list','certificate_origin','insurance','customs','photo','contract','other'))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT ck_documents_visibility CHECK (visibility IN ('internal','client','confidential'))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT ck_documents_status CHECK (status IN ('missing','received','validated'))");
        DB::statement('ALTER TABLE documents ADD CONSTRAINT ck_documents_attachment CHECK (shipment_id IS NOT NULL OR party_id IS NOT NULL)');

        Schema::create('document_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('document_id');
            $table->unsignedInteger('version');
            $table->string('s3_key')->comment('Jamais exposée — URLs signées à la demande');
            $table->string('original_filename');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum_sha256', 64);
            $table->string('av_scan_status')->default('pending');
            $table->uuid('uploaded_by')->nullable();
            $table->uuid('uploaded_by_portal')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users');
            $table->foreign('uploaded_by_portal')->references('id')->on('portal_accounts');
            $table->unique(['document_id', 'version'], 'ux_document_versions_version');
        });
        DB::statement("ALTER TABLE document_versions ADD CONSTRAINT ck_document_versions_av CHECK (av_scan_status IN ('pending','clean','infected','error'))");

        Schema::create('document_downloads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('document_version_id');
            $table->uuid('user_id')->nullable();
            $table->uuid('portal_account_id')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->timestampTz('downloaded_at')->useCurrent();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('document_version_id')->references('id')->on('document_versions');
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('portal_account_id')->references('id')->on('portal_accounts');
            $table->index(['document_version_id', 'downloaded_at'], 'ix_document_downloads_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_downloads');
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
    }
};
