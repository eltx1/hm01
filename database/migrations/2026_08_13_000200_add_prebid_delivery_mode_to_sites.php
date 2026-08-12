<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('prebid_delivery_mode', 32)->default('AUTO')->after('prebid_enabled')->index();
        });

        // Preserve the behavior that existed before AUTO was introduced.
        // Established direct sites were standalone; every other existing site
        // was explicitly routed through the GAM bridge by the old resolver.
        DB::table('sites')
            ->where('serving_mode', 'HORUS_DIRECT')
            ->update(['prebid_delivery_mode' => 'STANDALONE']);
        DB::table('sites')
            ->where('serving_mode', '!=', 'HORUS_DIRECT')
            ->update(['prebid_delivery_mode' => 'GAM_BRIDGE']);
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropIndex(['prebid_delivery_mode']);
            $table->dropColumn('prebid_delivery_mode');
        });
    }
};
