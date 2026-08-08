<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_configs', function (Blueprint $table): void {
            $table->json('click_guard_settings')->nullable()->after('observability_settings');
        });
    }

    public function down(): void
    {
        Schema::table('site_configs', function (Blueprint $table): void {
            $table->dropColumn('click_guard_settings');
        });
    }
};
