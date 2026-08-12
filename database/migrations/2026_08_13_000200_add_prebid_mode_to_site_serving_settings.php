<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_serving_settings', function (Blueprint $table): void {
            $table->string('prebid_configured_mode', 24)->default('AUTO')->after('prebid_enabled')->index();
        });
    }

    public function down(): void
    {
        Schema::table('site_serving_settings', function (Blueprint $table): void {
            $table->dropIndex(['prebid_configured_mode']);
            $table->dropColumn('prebid_configured_mode');
        });
    }
};
