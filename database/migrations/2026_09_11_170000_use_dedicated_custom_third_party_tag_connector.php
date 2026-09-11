<?php

use App\Services\Demand\ConfiguredDemandConnector;
use App\Services\Demand\CustomThirdPartyTagConnector;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('demand_networks')
            ->where('code', 'CUSTOM_THIRD_PARTY_TAG')
            ->where('connector_class', ConfiguredDemandConnector::class)
            ->update([
                'connector_class' => CustomThirdPartyTagConnector::class,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('demand_networks')
            ->where('code', 'CUSTOM_THIRD_PARTY_TAG')
            ->where('connector_class', CustomThirdPartyTagConnector::class)
            ->update([
                'connector_class' => ConfiguredDemandConnector::class,
                'updated_at' => now(),
            ]);
    }
};
