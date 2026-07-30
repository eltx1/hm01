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
        $minified = public_path('assets/hm-loader.min.js');
        $checksum = is_file($minified) ? hash_file('sha256', $minified) : hash('sha256', 'hm-loader-1.3.0');

        LoaderRelease::query()->where('version', '!=', '1.3.0')->update(['is_active' => false]);
        LoaderRelease::query()->updateOrCreate(
            ['version' => '1.3.0'],
            [
                'source_path' => 'assets/hm-loader.js',
                'minified_path' => 'assets/hm-loader.min.js',
                'checksum' => $checksum,
                'is_active' => true,
                'notes' => 'Browser Prebid and GAM delivery with modular native-demand fallback controlled by static configuration.',
                'published_at' => now(),
            ],
        );

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
