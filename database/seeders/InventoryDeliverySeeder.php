<?php

namespace Database\Seeders;

use App\Models\LoaderRelease;
use App\Models\TagVersion;
use Illuminate\Database\Seeder;

class InventoryDeliverySeeder extends Seeder
{
    public function run(): void
    {
        $source = public_path('assets/hm-loader.js');
        $checksum = is_file($source) ? hash_file('sha256', $source) : hash('sha256', 'hm-loader-1.1.0');

        LoaderRelease::query()->updateOrCreate(
            ['version' => '1.1.0'],
            [
                'source_path' => 'assets/hm-loader.js',
                'minified_path' => 'assets/hm-loader.min.js',
                'checksum' => $checksum,
                'is_active' => true,
                'notes' => 'Browser-side Prebid.js auctions with fail-safe GAM delivery.',
                'published_at' => now(),
            ],
        );
        LoaderRelease::query()->where('version', '!=', '1.1.0')->update(['is_active' => false]);

        TagVersion::query()->updateOrCreate(
            ['version' => '1.0.0'],
            [
                'gpt_url' => config('horus.gpt_url'),
                'settings' => ['singleRequest' => true],
                'checksum' => hash('sha256', config('horus.gpt_url').'|1.0.0'),
                'is_active' => true,
                'published_at' => now(),
            ],
        );
    }
}
