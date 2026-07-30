<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('locked_until')->nullable()->after('last_failed_login_at')->index();
            $table->string('lock_reason', 120)->nullable()->after('locked_until');
            $table->timestamp('password_changed_at')->nullable()->after('lock_reason');
        });

        Schema::create('platform_controls', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('scope_type', 32);
            $table->string('scope_id', 26)->default('GLOBAL');
            $table->string('control_key', 48);
            $table->boolean('is_disabled')->default(false)->index();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignUlid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();
            $table->unique(['scope_type', 'scope_id', 'control_key'], 'platform_controls_scope_unique');
            $table->index(['scope_type', 'scope_id', 'is_disabled'], 'platform_controls_lookup_index');
        });

        Schema::create('system_heartbeats', function (Blueprint $table): void {
            $table->string('key', 80)->primary();
            $table->string('status', 24)->default('HEALTHY');
            $table->timestamp('last_seen_at')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_heartbeats');
        Schema::dropIfExists('platform_controls');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['locked_until', 'lock_reason', 'password_changed_at']));
    }
};
