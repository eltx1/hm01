<?php

namespace Tests\Feature;

use App\Models\SystemHeartbeat;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SchedulerHeartbeatRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00', 'UTC'));
    }

    public function test_only_the_heartbeat_loses_overlap_protection_and_remains_every_minute(): void
    {
        $heartbeat = $this->scheduled('operations:heartbeat scheduler');
        $this->assertSame('* * * * *', $heartbeat->expression);
        $this->assertFalse($heartbeat->withoutOverlapping);
        $this->assertFalse($heartbeat->runInBackground);
        $this->assertFalse($heartbeat->onOneServer);

        foreach ([
            ['static-delivery:process', 10],
            ['adtech:probe', 10],
            ['monetization:health-check', 10],
            ['supply-chain:check', 30],
            ['support:sla-monitor', 10],
            ['notifications:deliver-email', 10],
            ['queue:work database', 1440],
            ['campaigns:monitor --reconcile', 1440],
            ['data-retention:prune --execute', 180],
            ['reporting:import hourly --retry-failed', 1440],
            ['reporting:sync-site-gam', 1440],
            ['reporting:sync-site-gam-video', 1440],
            ['reporting:import daily --retry-failed', 1440],
            ['reporting:close-period --force', 1440],
        ] as [$command, $expiresAt]) {
            $event = $this->scheduled($command);
            $this->assertTrue($event->withoutOverlapping, $command);
            $this->assertSame($expiresAt, $event->expiresAt, $command);
        }
    }

    public function test_stranded_heartbeat_mutex_does_not_block_the_isolated_event_or_get_deleted(): void
    {
        $event = $this->scheduled('operations:heartbeat scheduler');
        $legacy = clone $event;
        $legacy->withoutOverlapping();
        $this->assertTrue($legacy->mutex->create($legacy));
        $this->assertTrue($legacy->mutex->exists($legacy));
        // Demonstrate the original failure using the same mutex and command key.
        $this->assertTrue($legacy->shouldSkipDueToOverlapping());
        $this->assertFalse($legacy->filtersPass($this->app));
        $this->assertSame($legacy->mutexName(), $event->mutexName());
        $this->assertFalse($event->shouldSkipDueToOverlapping());
        $this->assertTrue($event->isDue($this->app));
        $this->assertTrue($event->filtersPass($this->app));

        SystemHeartbeat::query()->create([
            'key' => 'scheduler', 'status' => 'HEALTHY', 'last_seen_at' => now()->subMinutes(10),
        ]);
        // SQLite :memory: cannot be shared with a child process. Preserve the
        // registered event's public configuration and real run/start/finish
        // lifecycle; replace only its subprocess with the same heartbeat command.
        // Never invoke the complete scheduler or any reporting/delivery command.
        $runner = new class($event->mutex, $event->command, $event->timezone) extends Event {
            public int $executions = 0;

            protected function execute($container)
            {
                $this->executions++;
                return $container->make(Kernel::class)->call('operations:heartbeat', ['key' => 'scheduler']);
            }
        };
        foreach (get_object_vars($event) as $property => $value) $runner->{$property} = $value;
        $runner->run($this->app);

        $this->assertSame(1, $runner->executions);
        $this->assertSame(0, $runner->exitCode);
        $this->assertTrue(SystemHeartbeat::query()->findOrFail('scheduler')->last_seen_at->equalTo(now()));
        $this->assertTrue($legacy->mutex->exists($legacy), 'The old lock must remain for natural expiry.');
        Http::assertNothingSent();
    }

    public function test_missing_cron_never_creates_a_heartbeat_just_by_loading_or_inspecting_the_schedule(): void
    {
        $event = $this->scheduled('operations:heartbeat scheduler');
        $this->assertTrue($event->isDue($this->app));
        $this->assertTrue($event->filtersPass($this->app));
        $this->assertDatabaseCount('system_heartbeats', 0);
        $this->travel(6)->minutes();
        $this->scheduled('operations:heartbeat scheduler');
        $this->assertDatabaseCount('system_heartbeats', 0);
    }

    public function test_without_an_executed_command_an_existing_heartbeat_remains_stale(): void
    {
        $stale = now()->subMinutes(10);
        SystemHeartbeat::query()->create(['key' => 'scheduler', 'status' => 'HEALTHY', 'last_seen_at' => $stale]);
        $event = $this->scheduled('operations:heartbeat scheduler');
        $this->assertFalse($event->shouldSkipDueToOverlapping());
        $this->assertTrue($event->filtersPass($this->app));
        $this->travel(2)->minutes();
        $heartbeat = SystemHeartbeat::query()->findOrFail('scheduler');
        $this->assertTrue($heartbeat->last_seen_at->equalTo($stale));
        $this->assertTrue($heartbeat->last_seen_at->lt(now()->subMinutes(5)));
    }

    public function test_atomic_upsert_creates_then_refreshes_one_row_with_existing_metadata_and_timestamp_contract(): void
    {
        $this->artisan('operations:heartbeat')->expectsOutput('Heartbeat recorded.')->assertSuccessful();
        $first = SystemHeartbeat::query()->findOrFail('scheduler');
        $createdAt = $first->created_at->copy();
        $this->assertSame('HEALTHY', $first->status);
        $this->assertTrue($first->last_seen_at->equalTo(now()));
        $this->assertSame(['hostname' => gethostname() ?: null, 'php' => PHP_VERSION], $first->metadata);

        $this->travel(1)->minutes();
        $this->artisan('operations:heartbeat')->assertSuccessful();
        $next = $first->fresh();
        $this->assertDatabaseCount('system_heartbeats', 1);
        $this->assertTrue($next->created_at->equalTo($createdAt));
        $this->assertTrue($next->updated_at->equalTo(now()));
        $this->assertTrue($next->last_seen_at->equalTo(now()));
        $this->assertSame($first->metadata, $next->metadata);

        // Duplicate first-insert proposals resolve through the primary key rather
        // than a read-then-insert window. Repetition must remain one row.
        $this->artisan('operations:heartbeat')->assertSuccessful();
        $this->assertDatabaseCount('system_heartbeats', 1);
    }

    public function test_each_command_uses_one_native_upsert_without_a_read_before_insert_race(): void
    {
        foreach ([false, true] as $alreadyExists) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $this->artisan('operations:heartbeat')->assertSuccessful();
                $queries = collect(DB::getQueryLog())->pluck('query')
                    ->filter(fn (string $sql): bool => str_contains($sql, 'system_heartbeats'))->values();
            } finally {
                DB::disableQueryLog();
            }
            $this->assertCount(1, $queries, $alreadyExists ? 'Existing row' : 'First insert');
            $this->assertMatchesRegularExpression('/^insert\s+into\s/i', $queries[0]);
            $this->assertMatchesRegularExpression('/on\s+(?:conflict\s*\(.*?\)\s+do\s+update|duplicate\s+key\s+update)/i', $queries[0]);
            $this->assertStringNotContainsString('select', strtolower($queries[0]));
        }
    }

    public function test_named_heartbeat_does_not_change_the_scheduler_row(): void
    {
        $stale = now()->subMinutes(10);
        SystemHeartbeat::query()->create(['key' => 'scheduler', 'status' => 'HEALTHY', 'last_seen_at' => $stale]);
        $this->artisan('operations:heartbeat', ['key' => 'fixture-worker'])->assertSuccessful();
        $this->assertDatabaseCount('system_heartbeats', 2);
        $this->assertTrue(SystemHeartbeat::query()->findOrFail('scheduler')->last_seen_at->equalTo($stale));
        $this->assertTrue(SystemHeartbeat::query()->findOrFail('fixture-worker')->last_seen_at->equalTo(now()));
    }

    private function scheduled(string $command): Event
    {
        $this->app->make(Kernel::class)->bootstrap();
        $matches = collect($this->app->make(Schedule::class)->events())->filter(
            fn (Event $event): bool => str_contains((string) $event->command, $command)
                && ($command !== 'reporting:sync-site-gam' || !str_contains((string) $event->command, 'reporting:sync-site-gam-video'))
        )->values();
        $this->assertCount(1, $matches, $command);
        return $matches[0];
    }
}
