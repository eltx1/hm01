<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publisher_application_legal_acceptances', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('publisher_application_id');
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('document_type', 64);
            $table->string('document_version', 120);
            $table->string('canonical_url', 2048);
            $table->timestamp('accepted_at');
            $table->string('request_evidence_hash', 64);
            $table->string('evidence_hash', 64);
            $table->unique(['publisher_application_id', 'user_id', 'document_type', 'document_version'], 'pa_legal_acceptance_unique');
            $table->index(['publisher_application_id', 'document_type'], 'pa_legal_acceptance_type_index');
            $table->foreign('publisher_application_id', 'pa_legal_acceptance_app_foreign')->references('id')->on('publisher_applications')->cascadeOnDelete();
        });

        Schema::create('publisher_application_marketing_consents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('publisher_application_id');
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $table->boolean('opted_in')->default(false);
            $table->timestamp('recorded_at');
            $table->string('request_evidence_hash', 64);
            $table->string('evidence_hash', 64);
            $table->index(['publisher_application_id', 'recorded_at'], 'pa_marketing_consent_app_index');
            $table->foreign('publisher_application_id', 'pa_marketing_consent_app_foreign')->references('id')->on('publisher_applications')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publisher_application_marketing_consents');
        Schema::dropIfExists('publisher_application_legal_acceptances');
    }
};
