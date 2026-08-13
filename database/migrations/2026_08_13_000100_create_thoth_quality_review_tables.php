<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_connections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('provider', 20)->unique();
            $table->string('model', 100);
            $table->text('encrypted_credential')->nullable();
            $table->string('credential_source', 20)->default('NONE');
            $table->string('status', 30)->default('NOT_CONFIGURED');
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('last_connected_at')->nullable();
            $table->unsignedInteger('last_test_latency_ms')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('thoth_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('active_provider', 20)->default('OPENAI');
            $table->unsignedSmallInteger('timeout_seconds')->default(20);
            $table->unsignedInteger('max_output_tokens')->default(1800);
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('publisher_quality_profiles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('publisher_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('content_categories');
            $table->text('content_description');
            $table->json('traffic_profile');
            $table->json('audience_countries');
            $table->json('device_mix');
            $table->json('declarations');
            $table->text('review_comments')->nullable();
            $table->foreignUlid('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['publisher_id', 'version']);
        });

        Schema::create('publisher_quality_review_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('publisher_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('profile_id')->constrained('publisher_quality_profiles');
            $table->foreignUlid('requested_by')->constrained('users');
            $table->string('status', 30)->default('PENDING');
            $table->string('provider', 20);
            $table->string('model', 100);
            $table->string('provider_request_id', 255)->nullable();
            $table->string('policy_version', 80);
            $table->string('schema_version', 40);
            $table->json('evidence_snapshot');
            $table->string('evidence_hash', 64);
            $table->json('result')->nullable();
            $table->json('usage')->nullable();
            $table->string('response_hash', 64)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->string('active_dedupe_key', 100)->nullable()->unique();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['publisher_id', 'created_at']);
        });

        Schema::create('publisher_quality_decisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('publisher_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('review_run_id')->nullable()->constrained('publisher_quality_review_runs')->nullOnDelete();
            $table->string('decision', 30);
            $table->text('reason');
            $table->string('previous_status', 30);
            $table->string('new_status', 30);
            $table->foreignUlid('decided_by')->constrained('users');
            $table->timestamps();
            $table->index(['publisher_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publisher_quality_decisions');
        Schema::dropIfExists('publisher_quality_review_runs');
        Schema::dropIfExists('publisher_quality_profiles');
        Schema::dropIfExists('thoth_settings');
        Schema::dropIfExists('ai_provider_connections');
    }
};
