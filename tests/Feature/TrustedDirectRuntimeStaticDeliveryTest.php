<?php

namespace Tests\Feature;

use App\Services\StaticDelivery\StaticDeliverySnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TrustedDirectRuntimeStaticDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_static_snapshot_contains_stable_trusted_direct_demand_runtimes(): void
    {
        config([
            'static-delivery.file_budget.warning_threshold' => 100,
            'static-delivery.file_budget.hard_limit' => 200,
        ]);

        $snapshot = app(StaticDeliverySnapshotBuilder::class)->build();

        $this->assertArrayHasKey('assets/hm-gpt-direct.js', $snapshot->files);
        $this->assertArrayHasKey('assets/hm-isolated-direct.js', $snapshot->files);
        $this->assertSame(
            file_get_contents(public_path('assets/hm-gpt-direct.js')),
            $snapshot->files['assets/hm-gpt-direct.js'],
        );
        $this->assertSame(
            file_get_contents(public_path('assets/hm-isolated-direct.js')),
            $snapshot->files['assets/hm-isolated-direct.js'],
        );
        $this->assertStringContainsString('/assets/hm-gpt-direct.js', $snapshot->files['_headers']);
        $this->assertStringContainsString('/assets/hm-isolated-direct.js', $snapshot->files['_headers']);
    }
}
