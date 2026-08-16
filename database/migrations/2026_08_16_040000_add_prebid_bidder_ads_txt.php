<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bidder_accounts', function (Blueprint $table): void {
            $table->string('ads_txt_requirement', 32)->default('UNKNOWN')->after('enabled')->index();
            $table->string('ads_txt_evidence_url', 1000)->nullable()->after('ads_txt_requirement');
            $table->timestamp('ads_txt_requirement_verified_at')->nullable()->after('ads_txt_evidence_url');
            $table->foreignUlid('ads_txt_requirement_reviewed_by')->nullable()->after('ads_txt_requirement_verified_at')->constrained('users')->nullOnDelete();
        });

        Schema::create('bidder_ads_txt_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('bidder_account_id')->constrained('bidder_accounts')->cascadeOnDelete();
            $table->foreignUlid('site_id')->nullable()->constrained('sites')->cascadeOnDelete();
            $table->string('advertising_system_domain', 255);
            $table->string('publisher_account_id', 255);
            $table->string('relationship', 16);
            $table->string('certification_authority_id', 128)->nullable();
            $table->text('raw_record');
            $table->string('record_hash', 64);
            $table->string('status', 24)->default('ACTIVE')->index();
            $table->string('review_status', 32)->default('REVIEW_REQUIRED')->index();
            $table->string('source', 32)->default('MANUAL');
            $table->string('remote_verification_status', 32)->default('UNVERIFIED')->index();
            $table->string('remote_error_code', 80)->nullable();
            $table->timestamp('remote_verified_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignUlid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['bidder_account_id', 'site_id', 'status'], 'bidder_ads_txt_scope_index');
            $table->index(['bidder_account_id', 'record_hash'], 'bidder_ads_txt_hash_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bidder_ads_txt_records');

        Schema::table('bidder_accounts', function (Blueprint $table): void {
            $table->dropForeign(['ads_txt_requirement_reviewed_by']);
            $table->dropColumn([
                'ads_txt_requirement', 'ads_txt_evidence_url', 'ads_txt_requirement_verified_at',
                'ads_txt_requirement_reviewed_by',
            ]);
        });
    }
};
