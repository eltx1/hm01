<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->index(['status', 'resolution_due_at'], 'support_tickets_status_resolution_sla');
        });

        Schema::create('horus_notifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('category', 32);
            $table->string('type', 96);
            $table->string('severity', 20);
            $table->string('title', 180);
            $table->string('message', 500);
            $table->string('related_type', 64)->nullable();
            $table->ulid('related_id')->nullable();
            $table->string('action_route', 140)->nullable();
            $table->json('action_parameters')->nullable();
            $table->char('dedupe_key', 64)->unique();
            $table->boolean('in_app_visible')->default(true);
            $table->boolean('email_requested')->default(false);
            $table->unsignedSmallInteger('email_attempts')->default(0);
            $table->timestamp('emailed_at')->nullable();
            $table->timestamp('email_failed_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['recipient_id', 'in_app_visible', 'read_at', 'created_at'], 'notifications_recipient_unread_created_index');
            $table->index(['organization_id', 'category', 'created_at'], 'notifications_org_category_created_index');
            $table->index(['email_requested', 'emailed_at', 'created_at'], 'notifications_email_delivery_index');
            $table->index(['related_type', 'related_id'], 'notifications_related_index');
        });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 32);
            $table->boolean('in_app_enabled')->default(true);
            $table->boolean('email_enabled')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('horus_notifications');
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->dropIndex('support_tickets_status_resolution_sla');
        });
    }
};
