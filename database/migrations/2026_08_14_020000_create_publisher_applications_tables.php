<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publisher_applications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignUlid('publisher_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignUlid('applicant_user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('primary_domain', 253)->index();
            $table->string('status', 40)->default('EMAIL_VERIFICATION_REQUIRED')->index();
            $table->unsignedInteger('current_revision')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('last_submitted_at')->nullable();
            $table->timestamp('review_started_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignUlid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'submitted_at']);
        });

        Schema::create('publisher_application_domain_claims', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('publisher_application_id');
            $table->string('normalized_domain', 253);
            $table->timestamps();
            $table->unique('publisher_application_id', 'pa_domain_claim_app_unique');
            $table->unique('normalized_domain', 'pa_domain_claim_domain_unique');
            $table->foreign('publisher_application_id', 'pa_domain_claim_app_foreign')->references('id')->on('publisher_applications')->cascadeOnDelete();
        });

        Schema::create('publisher_application_revisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('publisher_application_id');
            $table->ulid('publisher_quality_profile_id');
            $table->foreignUlid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->string('snapshot_hash', 64);
            $table->timestamp('submitted_at');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['publisher_application_id', 'version'], 'pa_revision_app_version_unique');
            $table->foreign('publisher_application_id', 'pa_revision_app_foreign')->references('id')->on('publisher_applications')->cascadeOnDelete();
            $table->foreign('publisher_quality_profile_id', 'pa_revision_profile_foreign')->references('id')->on('publisher_quality_profiles')->restrictOnDelete();
        });

        Schema::create('publisher_application_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('publisher_application_id');
            $table->ulid('publisher_application_revision_id')->nullable();
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->string('previous_status', 40)->nullable();
            $table->string('new_status', 40);
            $table->text('reason')->nullable();
            $table->boolean('applicant_visible')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['publisher_application_id', 'created_at'], 'pa_event_app_created_index');
            $table->index(['action', 'created_at'], 'pa_event_action_created_index');
            $table->foreign('publisher_application_id', 'pa_event_app_foreign')->references('id')->on('publisher_applications')->cascadeOnDelete();
            $table->foreign('publisher_application_revision_id', 'pa_event_revision_foreign')->references('id')->on('publisher_application_revisions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publisher_application_events');
        Schema::dropIfExists('publisher_application_revisions');
        Schema::dropIfExists('publisher_application_domain_claims');
        Schema::dropIfExists('publisher_applications');
    }
};
