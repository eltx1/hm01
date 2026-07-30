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
        });
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->string('key', 80)->primary();
            $table->json('value')->nullable();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('cron_heartbeats', function (Blueprint $table): void {
            $table->string('name', 120)->primary();
            $table->string('status', 24)->default('UNKNOWN')->index();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_completed_at')->nullable()->index();
            $table->timestamp('last_failed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cron_heartbeats');
        Schema::dropIfExists('platform_settings');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('locked_until'));
    }
};
