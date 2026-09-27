<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['hourly_reports', 'daily_reports', 'monthly_reports'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                // NULL means not supplied by the source, including historical rows.
                $table->unsignedBigInteger('active_view_viewable_impressions')->nullable();
                $table->unsignedBigInteger('active_view_measurable_impressions')->nullable();
                $table->unsignedBigInteger('unfilled_impressions')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['hourly_reports', 'daily_reports', 'monthly_reports'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn([
                'active_view_viewable_impressions', 'active_view_measurable_impressions', 'unfilled_impressions',
            ]));
        }
    }
};
