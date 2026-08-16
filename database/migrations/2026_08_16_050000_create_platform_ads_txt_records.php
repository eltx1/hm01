<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_ads_txt_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('advertising_system_domain', 255);
            $table->string('publisher_account_id', 255);
            $table->string('relationship', 16);
            $table->string('certification_authority_id', 128)->nullable();
            $table->text('raw_record');
            $table->string('record_hash', 64);
            $table->string('status', 24)->default('DISABLED')->index();
            $table->string('review_status', 32)->default('REVIEW_REQUIRED')->index();
            $table->timestamp('effective_from')->nullable()->index();
            $table->timestamp('effective_to')->nullable()->index();
            $table->timestamp('last_verified_at')->nullable();
            $table->string('remote_verification_status', 32)->default('UNVERIFIED')->index();
            $table->string('remote_error_code', 80)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignUlid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('internal_notes')->nullable();
            $table->timestamps();
            $table->unique(['advertising_system_domain', 'publisher_account_id'], 'platform_ads_txt_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_ads_txt_records');
    }
};
