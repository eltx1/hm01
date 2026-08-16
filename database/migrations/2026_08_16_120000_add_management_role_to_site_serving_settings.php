<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_serving_settings', function (Blueprint $table): void {
            $table->string('monetization_manager_role', 40)->default('NONE')->after('native_demand_enabled');
        });

        DB::table('site_serving_settings')
            ->select(['id', 'monetization_manager_domain', 'monetization_manager_relationship', 'monetization_manager_country'])
            ->orderBy('id')
            ->get()
            ->each(function (object $setting): void {
                $domain = strtolower(trim((string) $setting->monetization_manager_domain));
                $relationship = strtoupper(trim((string) $setting->monetization_manager_relationship));
                $country = strtoupper(trim((string) $setting->monetization_manager_country));

                if ($domain !== 'horusmedia.net' || ! in_array($relationship, ['PRIMARY', 'EXCLUSIVE'], true)) {
                    return;
                }
                if ($country !== '' && preg_match('/^[A-Z]{2}$/', $country) !== 1) {
                    return;
                }

                DB::table('site_serving_settings')->where('id', $setting->id)->update([
                    'monetization_manager_role' => 'HORUS_'.$relationship.'_'.($country === '' ? 'GLOBAL' : 'COUNTRY'),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('site_serving_settings', function (Blueprint $table): void {
            $table->dropColumn('monetization_manager_role');
        });
    }
};
