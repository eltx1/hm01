<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_declarations', function (Blueprint $table): void {
            $table->string('ads_txt_relationship', 8)->nullable()->after('seller_type');
        });

        Schema::table('site_serving_settings', function (Blueprint $table): void {
            $table->string('monetization_manager_domain', 253)->nullable()->after('native_demand_enabled');
            $table->string('monetization_manager_relationship', 16)->nullable()->after('monetization_manager_domain');
            $table->string('monetization_manager_country', 2)->nullable()->after('monetization_manager_relationship');
        });
    }

    public function down(): void
    {
        Schema::table('site_serving_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'monetization_manager_domain',
                'monetization_manager_relationship',
                'monetization_manager_country',
            ]);
        });

        Schema::table('seller_declarations', function (Blueprint $table): void {
            $table->dropColumn('ads_txt_relationship');
        });
    }
};
