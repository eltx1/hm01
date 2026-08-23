<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_quality_review_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('publisher_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trigger', 20)->default('AUTOMATIC');
            $table->string('status', 30)->default('QUEUED');
            $table->string('provider', 20)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('provider_request_id', 255)->nullable();
            $table->string('policy_version', 80);
            $table->string('schema_version', 40);
            $table->json('evidence_snapshot')->nullable();
            $table->string('evidence_hash', 64)->nullable();
            $table->json('result')->nullable();
            $table->json('usage')->nullable();
            $table->string('response_hash', 64)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->string('active_dedupe_key', 100)->nullable()->unique();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'created_at']);
            $table->index(['publisher_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_quality_review_runs');
    }
};
