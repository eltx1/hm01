<?php

namespace Tests\Feature;

use App\Services\StaticDelivery\StaticDeliverySnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TrustedDirectRuntimeStaticDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_static_snapshot_contains_rollback_retained_content_addressed_trusted_runtimes(): void
    {
        config([
            'static-delivery.file_budget.warning_threshold' => 100,
            'static-delivery.file_budget.hard_limit' => 200,
        ]);

        $snapshot = app(StaticDeliverySnapshotBuilder::class)->build();
        $gpt = file_get_contents(public_path('assets/hm-gpt-direct.js'));
        $isolated = file_get_contents(public_path('assets/hm-isolated-direct.js'));
        $video = file_get_contents(public_path('assets/hm-video-direct.js'));
        $this->assertIsString($gpt);
        $this->assertIsString($isolated);
        $this->assertIsString($video);

        $gptRuntime = 'runtime/gpt/hm-gpt-direct.'.substr(hash('sha256', $gpt), 0, 16).'.js';
        $isolatedRuntime = 'runtime/direct/hm-isolated-direct.'.substr(hash('sha256', $isolated), 0, 16).'.js';
        $videoRuntime = 'runtime/video/hm-video-direct.'.substr(hash('sha256', $video), 0, 16).'.js';

        $this->assertArrayHasKey('assets/hm-gpt-direct.js', $snapshot->files);
        $this->assertArrayHasKey('assets/hm-isolated-direct.js', $snapshot->files);
        $this->assertArrayHasKey('assets/hm-video-direct.js', $snapshot->files);
        $this->assertArrayHasKey($gptRuntime, $snapshot->files);
        $this->assertArrayHasKey($isolatedRuntime, $snapshot->files);
        $this->assertArrayHasKey($videoRuntime, $snapshot->files);
        $this->assertSame($gpt, $snapshot->files['assets/hm-gpt-direct.js']);
        $this->assertSame($isolated, $snapshot->files['assets/hm-isolated-direct.js']);
        $this->assertSame($video, $snapshot->files['assets/hm-video-direct.js']);
        $this->assertSame($gpt, $snapshot->files[$gptRuntime]);
        $this->assertSame($isolated, $snapshot->files[$isolatedRuntime]);
        $this->assertSame($video, $snapshot->files[$videoRuntime]);
        $this->assertStringContainsString('/assets/hm-gpt-direct.js', $snapshot->files['_headers']);
        $this->assertStringContainsString('/assets/hm-isolated-direct.js', $snapshot->files['_headers']);
        $this->assertStringContainsString('/assets/hm-video-direct.js', $snapshot->files['_headers']);
        $this->assertStringContainsString('/runtime/*', $snapshot->files['_headers']);
    }
}
