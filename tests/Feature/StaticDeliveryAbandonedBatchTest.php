<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\StaticDeliveryBatch;
use App\Models\StaticGlobalArtifactChange;
use App\Services\StaticDelivery\Contracts\StaticDeliveryDriverInterface;
use App\Services\StaticDelivery\StaticDeliveryManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\FakeStaticDeliveryDriver;
use Tests\TestCase;

class StaticDeliveryAbandonedBatchTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('recoveryCases')]
    public function test_abandoned_global_work_needs_newer_confirmed_evidence(string $case, bool $superseded): void
    {
        $driver = new FakeStaticDeliveryDriver;
        $this->app->instance(StaticDeliveryDriverInterface::class, $driver);
        Http::preventStrayRequests();
        $old = now()->subDays(18);
        $batch = StaticDeliveryBatch::forceCreate(['driver' => $driver->name(), 'status' => 'BATCHING',
            'priority' => 'NORMAL', 'trigger' => 'SCHEDULED', 'attempts' => 5,
            'started_at' => $case === 'legacy_missing_start' ? null : $old,
            'created_at' => $old, 'updated_at' => $case === 'fresh' ? now() : $old,
            'submitted_at' => $case === 'submitted' ? $old : null,
            'remote_url' => $case === 'remote_url' ? 'https://example.pages.dev' : null,
            'remote_deployment_id' => $case === 'remote_identity' ? 'existing-deployment' : null]);
        $item = StaticGlobalArtifactChange::forceCreate(['artifact_type' => 'SUPPLY_CHAIN', 'batch_id' => $batch->id,
            'status' => 'BATCHING', 'priority' => 'NORMAL', 'attempts' => 5,
            'available_at' => $old, 'created_at' => $old, 'updated_at' => $old]);
        if ($case === 'partial_coverage' || $case === 'mixed_status') {
            StaticGlobalArtifactChange::forceCreate(['artifact_type' => 'OTHER', 'batch_id' => $batch->id,
                'status' => $case === 'mixed_status' ? 'UPLOADING' : 'BATCHING', 'priority' => 'NORMAL',
                'available_at' => $old, 'created_at' => $old, 'updated_at' => $old]);
        }
        $confirmed = StaticDeliveryBatch::create(['driver' => $case === 'other_driver' ? 'other' : $driver->name(),
            'status' => $case === 'unconfirmed' ? 'FAILED' : 'DEPLOYED', 'priority' => 'NORMAL', 'trigger' => 'SCHEDULED',
            'manifest_hash' => $case === 'missing_hash' ? null : str_repeat('a', 64),
            'started_at' => $case === 'older_snapshot' ? $old->copy()->subHour() : now()->subMinutes(2),
            'deployed_at' => now()->subMinute()]);
        StaticGlobalArtifactChange::create(['artifact_type' => $case === 'other_artifact' ? 'OTHER' : 'SUPPLY_CHAIN',
            'batch_id' => $confirmed->id, 'status' => 'DEPLOYED', 'priority' => 'NORMAL',
            'available_at' => now()->subMinutes(2), 'delivered_at' => now()->subMinute()]);

        $manager = app(StaticDeliveryManager::class);
        $this->assertNull($manager->processPending());
        $this->assertSame($superseded ? 'SUPERSEDED' : 'BATCHING', $batch->fresh()->status->value);
        $this->assertSame($superseded ? 'SUPERSEDED' : 'BATCHING', $item->fresh()->status->value);
        $this->assertNull($batch->fresh()->deployed_at);
        $this->assertSame(5, $item->fresh()->attempts);
        $this->assertSame($case === 'unconfirmed' ? 'FAILED' : 'DEPLOYED', $confirmed->fresh()->status->value);
        $this->assertCount(0, $driver->snapshots);
        $manager->processPending();
        $events = AuditLog::query()->where('event', 'static.delivery.abandoned_batch.superseded')->get();
        $this->assertCount($superseded ? 1 : 0, $events);
        if ($superseded) {
            $this->assertSame([$confirmed->id], $events->first()->new_values['confirmed_batch_ids']);
        }
        Http::assertNothingSent();
    }

    public static function recoveryCases(): array
    {
        return [
            'covered stale global work' => ['covered', true],
            'legacy batch without start time' => ['legacy_missing_start', true],
            'fresh batching record' => ['fresh', false],
            'previously submitted work' => ['submitted', false],
            'provider identity present' => ['remote_identity', false],
            'provider URL present' => ['remote_url', false],
            'other provider' => ['other_driver', false],
            'unconfirmed replacement' => ['unconfirmed', false],
            'missing manifest evidence' => ['missing_hash', false],
            'snapshot predates intent' => ['older_snapshot', false],
            'different artifact kind' => ['other_artifact', false],
            'not every change covered' => ['partial_coverage', false],
            'mixed item statuses' => ['mixed_status', false],
        ];
    }
}
