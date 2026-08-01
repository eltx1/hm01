<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('static_delivery_batches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('status', 24)->index();
            $table->string('priority', 16)->default('NORMAL')->index();
            $table->string('driver', 32);
            $table->string('manifest_hash', 64)->nullable()->index();
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedInteger('file_count')->default(0);
            $table->unsignedBigInteger('total_bytes')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('remote_deployment_id')->nullable()->index();
            $table->text('remote_url')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('deployed_at')->nullable();
            $table->timestamp('next_retry_at')->nullable()->index();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'priority', 'created_at'], 'static_delivery_batches_queue_index');
        });

        Schema::create('static_delivery_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignUlid('config_version_id')->unique()->constrained('config_versions')->cascadeOnDelete();
            $table->foreignUlid('batch_id')->nullable()->constrained('static_delivery_batches')->nullOnDelete();
            $table->string('environment', 20)->index();
            $table->string('status', 24)->index();
            $table->string('priority', 16)->default('NORMAL')->index();
            $table->string('checksum', 64);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->index();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['site_id', 'environment', 'status'], 'static_delivery_items_dedupe_index');
            $table->index(['status', 'priority', 'available_at'], 'static_delivery_items_queue_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('static_delivery_items');
        Schema::dropIfExists('static_delivery_batches');
    }
};
