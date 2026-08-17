<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_serving_settings', function (Blueprint $table): void {
            $table->string('traffic_gate_state', 16)->default('INHERIT')->after('native_demand_enabled');
            $table->string('traffic_gate_policy', 16)->default('INHERIT')->after('traffic_gate_state');
        });
    }

    public function down(): void
    {
        Schema::table('site_serving_settings', function (Blueprint $table): void {
            $table->dropColumn(['traffic_gate_state', 'traffic_gate_policy']);
        });
    }
};
