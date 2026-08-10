<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index(['created_at', 'id'], 'audit_created_id_idx');
            $table->index(['actor_id', 'created_at'], 'audit_actor_created_idx');
            $table->index(['event', 'created_at'], 'audit_event_created_idx');
            $table->index(['auditable_type', 'auditable_id', 'created_at'], 'audit_entity_created_idx');
            $table->index(['ip_address', 'created_at'], 'audit_ip_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_created_id_idx');
            $table->dropIndex('audit_actor_created_idx');
            $table->dropIndex('audit_event_created_idx');
            $table->dropIndex('audit_entity_created_idx');
            $table->dropIndex('audit_ip_created_idx');
        });
    }
};
