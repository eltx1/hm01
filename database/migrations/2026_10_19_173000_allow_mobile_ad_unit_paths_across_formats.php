<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_app_ad_units', function (Blueprint $table) {
            // Keep an app-leading index throughout the change for MySQL's FK.
            $table->unique(['mobile_app_id', 'ad_unit_path', 'format'], 'mobile_app_ad_units_app_path_format_unique');
            $table->dropUnique(['mobile_app_id', 'ad_unit_path']);
        });
    }

    public function down(): void
    {
        // Never discard valid units to restore the older, stricter constraint.
        if (DB::table('mobile_app_ad_units')->select('mobile_app_id', 'ad_unit_path')
            ->groupBy('mobile_app_id', 'ad_unit_path')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot roll back mobile ad unit format sharing while an app uses one path for multiple formats. Keep this migration or resolve those units explicitly first.');
        }

        Schema::table('mobile_app_ad_units', function (Blueprint $table) {
            $table->unique(['mobile_app_id', 'ad_unit_path']);
            $table->dropUnique('mobile_app_ad_units_app_path_format_unique');
        });
    }
};
