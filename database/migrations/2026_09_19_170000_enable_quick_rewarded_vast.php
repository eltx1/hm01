<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ad_formats')->where('code', 'rewarded')->update([
            'display_name' => 'Rewarded Video / Opt-in',
            'default_sizes' => json_encode([[640, 360], [320, 180]], JSON_THROW_ON_ERROR),
            'capabilities' => json_encode([
                'outOfPage' => true,
                'rewarded' => true,
                'vast' => '4.3',
                'responsive' => true,
                'providerAgnosticSurface' => true,
                'requiresProviderSupport' => true,
                'requiresUserActivation' => true,
                'providerManaged' => true,
                'horusDirectRuntime' => true,
                'quickCompatible' => true,
                'gamOutOfPageEnhanced' => true,
            ], JSON_THROW_ON_ERROR),
            'defaults' => json_encode([
                'requireReadyEvent' => true,
                'requireUserActivation' => true,
                'reserveSpace' => false,
                'rewardCooldownSeconds' => 900,
                'surface' => [
                    'family' => 'rewarded',
                    'mount' => 'provider_managed',
                    'position' => 'viewport',
                    'providerAgnostic' => true,
                    'requiresUserActivation' => true,
                ],
            ], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('ad_formats')->where('code', 'rewarded')->update([
            'display_name' => 'Rewarded / Opt-in',
            'default_sizes' => json_encode([[640, 480]], JSON_THROW_ON_ERROR),
            'capabilities' => json_encode([
                'outOfPage' => true,
                'rewarded' => true,
                'providerAgnosticSurface' => true,
                'requiresProviderSupport' => true,
                'requiresUserActivation' => true,
                'providerManaged' => true,
                'quickCompatible' => false,
                'gamOutOfPageEnhanced' => true,
            ], JSON_THROW_ON_ERROR),
            'defaults' => json_encode([
                'requireReadyEvent' => true,
                'requireUserActivation' => true,
                'reserveSpace' => false,
                'surface' => [
                    'family' => 'rewarded',
                    'mount' => 'provider_managed',
                    'position' => 'viewport',
                    'providerAgnostic' => true,
                    'requiresUserActivation' => true,
                ],
            ], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }
};
