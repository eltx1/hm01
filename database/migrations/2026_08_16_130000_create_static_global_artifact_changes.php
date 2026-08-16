<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('static_global_artifact_changes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('artifact_type', 40)->index();
            $table->foreignUlid('batch_id')->nullable()->constrained('static_delivery_batches')->nullOnDelete();
            $table->string('status', 24)->index();
            $table->string('priority', 16)->default('NORMAL')->index();
            $table->unsignedInteger('event_count')->default(1);
            $table->json('context')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->index();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['artifact_type', 'status', 'available_at'], 'static_global_artifact_queue_index');
            $table->index(['status', 'priority', 'available_at'], 'static_global_artifact_priority_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('static_global_artifact_changes');
    }
};
