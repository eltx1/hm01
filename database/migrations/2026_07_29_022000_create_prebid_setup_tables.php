<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prebid_setup_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('gam_connection_id')->constrained('gam_connections')->cascadeOnDelete();
            $table->foreignUlid('prebid_gam_template_id')->constrained('prebid_gam_templates')->cascadeOnDelete();
            $table->foreignUlid('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->index();
            $table->boolean('dry_run')->default(true);
            $table->boolean('confirmed')->default(false);
            $table->unsignedInteger('estimated_objects')->default(0);
            $table->json('counters')->nullable();
            $table->json('cursor')->nullable();
            $table->longText('plan')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['gam_connection_id', 'status'], 'prebid_setup_runs_connection_status_index');
        });

        Schema::create('prebid_gam_remote_objects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('gam_connection_id')->constrained('gam_connections')->cascadeOnDelete();
            $table->foreignUlid('prebid_setup_run_id')->nullable()->constrained('prebid_setup_runs')->nullOnDelete();
            $table->string('object_key', 191);
            $table->string('object_type', 40);
            $table->string('remote_object_id');
            $table->string('payload_hash', 64);
            $table->string('remote_status', 40)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['gam_connection_id', 'object_type', 'object_key'], 'prebid_gam_remote_objects_unique');
            $table->index(['organization_id', 'gam_connection_id', 'object_type'], 'prebid_gam_remote_objects_lookup_index');
        });

        Schema::create('prebid_errors', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('gam_connection_id')->nullable()->constrained('gam_connections')->nullOnDelete();
            $table->foreignUlid('prebid_setup_run_id')->nullable()->constrained('prebid_setup_runs')->nullOnDelete();
            $table->string('category', 80)->index();
            $table->string('code', 100)->nullable();
            $table->text('message');
            $table->boolean('retryable')->default(false)->index();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignUlid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prebid_errors');
        Schema::dropIfExists('prebid_gam_remote_objects');
        Schema::dropIfExists('prebid_setup_runs');
    }
};
