<?php

namespace Tests\Feature;

use App\Models\LoaderRelease;
use Database\Seeders\InventoryDeliverySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LoaderReleaseMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_seed_selects_the_actual_canonical_loader_version_and_checksum(): void
    {
        LoaderRelease::query()->create([
            'version' => '1.3.0',
            'source_path' => 'assets/hm-loader.js',
            'minified_path' => 'assets/hm-loader.min.js',
            'checksum' => str_repeat('0', 64),
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);

        $this->seed(InventoryDeliverySeeder::class);

        $current = LoaderRelease::query()->where('version', '2.0.0')->sole();
        $this->assertTrue($current->is_active);
        $this->assertSame('assets/hm-loader.js', $current->source_path);
        $this->assertSame('assets/hm-loader.min.js', $current->minified_path);
        $this->assertSame(hash_file('sha256', public_path('assets/hm-loader.min.js')), $current->checksum);
        $this->assertFalse(LoaderRelease::query()->where('version', '1.3.0')->sole()->is_active);
    }
}
