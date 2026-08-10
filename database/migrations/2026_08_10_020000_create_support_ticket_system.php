<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_sla_policies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            $table->string('priority', 20)->unique();
            $table->unsignedInteger('first_response_minutes');
            $table->unsignedInteger('resolution_minutes')->nullable();
            $table->unsignedInteger('warning_before_minutes')->default(30);
            $table->boolean('pause_while_waiting_on_customer')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('ticket_number', 40)->unique();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('requester_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('sla_policy_id')->nullable()->constrained('support_sla_policies')->nullOnDelete();
            $table->string('subject', 255);
            $table->string('category', 40);
            $table->string('priority', 20);
            $table->string('status', 30);
            $table->string('linked_resource_type', 40)->nullable();
            $table->ulid('linked_resource_id')->nullable();
            $table->timestamp('last_customer_reply_at')->nullable();
            $table->timestamp('last_horus_reply_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable();
            $table->timestamp('sla_paused_at')->nullable();
            $table->unsignedBigInteger('sla_paused_seconds')->default(0);
            $table->timestamps();
            $table->index(['organization_id', 'status', 'updated_at'], 'support_tickets_org_status_activity');
            $table->index(['assigned_to', 'status', 'updated_at'], 'support_tickets_assignee_status');
            $table->index(['priority', 'status', 'first_response_due_at'], 'support_tickets_priority_sla');
            $table->index(['linked_resource_type', 'linked_resource_id'], 'support_tickets_linked_resource');
        });

        Schema::create('support_ticket_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignUlid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20);
            $table->text('body');
            $table->timestamps();
            $table->index(['support_ticket_id', 'type', 'created_at'], 'support_ticket_messages_thread');
        });

        Schema::create('support_ticket_attachments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignUlid('support_ticket_message_id')->constrained('support_ticket_messages')->cascadeOnDelete();
            $table->foreignUlid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('storage_path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->string('extension', 20);
            $table->string('checksum_sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
            $table->index(['support_ticket_id', 'created_at'], 'support_ticket_attachments_ticket');
        });

        Schema::create('support_ticket_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->string('from_value', 120)->nullable();
            $table->string('to_value', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['support_ticket_id', 'created_at'], 'support_ticket_events_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_events');
        Schema::dropIfExists('support_ticket_attachments');
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('support_sla_policies');
    }
};
