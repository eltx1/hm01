<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_diagnostic_tokens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 24);
            $table->char('token_hash', 64)->unique();
            $table->json('allowed_hostnames');
            $table->unsignedSmallInteger('max_reports')->default(1);
            $table->unsignedSmallInteger('report_count')->default(0);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'environment', 'expires_at'], 'privacy_tokens_site_environment_expiry');
        });

        Schema::create('privacy_diagnostic_evidence', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('privacy_diagnostic_token_id')->nullable()->constrained('privacy_diagnostic_tokens')->nullOnDelete();
            $table->string('environment', 24);
            $table->string('result_status', 32);
            $table->string('loader_version', 64);
            $table->unsignedBigInteger('config_version')->nullable();
            $table->string('hostname', 255);
            $table->boolean('tcf_api_detected')->default(false);
            $table->boolean('tcf_api_responded')->default(false);
            $table->unsignedInteger('tcf_cmp_id')->nullable();
            $table->string('tcf_event_status', 64)->nullable();
            $table->boolean('gpp_api_detected')->default(false);
            $table->boolean('gpp_api_responded')->default(false);
            $table->json('gpp_applicable_sections')->nullable();
            $table->boolean('gpc_detected')->default(false);
            $table->string('configured_timeout_action', 32);
            $table->json('prebid_modules')->nullable();
            $table->boolean('prebid_consent_configured')->default(false);
            $table->boolean('prebid_storage_control_configured')->default(false);
            $table->boolean('prebid_activity_controls_configured')->default(false);
            $table->boolean('privacy_gate_respected')->default(false);
            $table->timestamp('observed_at');
            $table->char('result_hash', 64);
            $table->timestamps();
            $table->index(['site_id', 'environment', 'observed_at'], 'privacy_evidence_site_environment_observed');
        });

        Schema::create('google_cmp_evidence', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 24);
            $table->string('cmp_name', 255);
            $table->unsignedInteger('tcf_cmp_id');
            $table->string('platform', 64);
            $table->date('last_verification_date');
            $table->string('operator_verification_status', 32)->default('NOT_VERIFIED');
            $table->foreignUlid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['site_id', 'environment'], 'google_cmp_evidence_site_environment_unique');
        });

        Schema::table('prebid_bidders', function (Blueprint $table): void {
            $table->json('privacy_capabilities')->nullable()->after('default_public_parameters');
        });
        Schema::table('demand_networks', function (Blueprint $table): void {
            $table->json('privacy_capabilities')->nullable()->after('capabilities');
        });
    }

    public function down(): void
    {
        Schema::table('demand_networks', fn (Blueprint $table) => $table->dropColumn('privacy_capabilities'));
        Schema::table('prebid_bidders', fn (Blueprint $table) => $table->dropColumn('privacy_capabilities'));
        Schema::dropIfExists('google_cmp_evidence');
        Schema::dropIfExists('privacy_diagnostic_evidence');
        Schema::dropIfExists('privacy_diagnostic_tokens');
    }
};
