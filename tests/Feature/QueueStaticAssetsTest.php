<?php

namespace Tests\Feature;

use App\Models\StaticGlobalArtifactChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QueueStaticAssetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_release_assets_queue_once_without_uploading_or_bypassing_the_normal_window(): void
    {
        $original = public_path();
        $directory = sys_get_temp_dir().'/horus-assets-'.bin2hex(random_bytes(6));
        foreach (['assets/hm-loader.min.js', 'assets/hm-gpt-direct.js', 'assets/hm-isolated-direct.js',
            'assets/hm-video-direct.js', 'assets/prebid/horus-prebid.min.js',
            'traffic-gate/index.html', 'assets/traffic-gate/horus-traffic-gate.js'] as $path) {
            File::ensureDirectoryExists(dirname($directory.'/'.$path));
            File::put($directory.'/'.$path, 'fixture-'.$path);
        }
        $this->app->usePublicPath($directory);
        $this->travelTo(now()->utc()->startOfDay()->addHours(12)->addMinute());
        config(['static-delivery.normal_batch_interval_minutes' => 5]);
        Http::preventStrayRequests();
        try {
            $this->artisan('static-delivery:queue-assets')->assertSuccessful();
            $this->assertDatabaseCount('static_global_artifact_changes', 0);
            $this->artisan('static-delivery:queue-assets --apply')->assertSuccessful();
            $this->artisan('static-delivery:queue-assets --apply')->assertSuccessful();
            $this->assertDatabaseCount('static_global_artifact_changes', 1);
            $change = StaticGlobalArtifactChange::query()->firstOrFail();
            $this->assertSame('STATIC_ASSETS', $change->artifact_type);
            $this->assertSame('NORMAL', $change->priority->value);
            $this->assertSame('PENDING', $change->status->value);
            $this->assertSame('12:05', $change->available_at->utc()->format('H:i'));
            $this->assertDatabaseCount('static_delivery_batches', 0);

            // A periodic check must not create endless fresh attempts after failure.
            $change->update(['status' => 'FAILED', 'attempts' => 5]);
            $this->artisan('static-delivery:queue-assets --apply')->assertSuccessful();
            $this->assertDatabaseCount('static_global_artifact_changes', 1);
            $this->assertSame(5, $change->fresh()->attempts);

            // A loader-only release really does need a new static publication.
            $this->travel(1)->seconds();
            File::put($directory.'/assets/hm-loader.min.js', 'changed-loader');
            $this->artisan('static-delivery:queue-assets --apply')->assertSuccessful();
            $this->artisan('static-delivery:queue-assets --apply')->assertSuccessful();
            $this->assertDatabaseCount('static_global_artifact_changes', 2);
            $this->assertDatabaseCount('static_delivery_batches', 0);
            Http::assertNothingSent();
        } finally {
            $this->app->usePublicPath($original);
            File::deleteDirectory($directory);
            $this->travelBack();
        }
    }
}
