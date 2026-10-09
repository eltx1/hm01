<?php

namespace Tests\Feature;

use App\Services\Reporting\MainReportSyncLock;
use App\Services\Reporting\MainReportSyncLockException;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/** Real sessions and real processes; deliberately no wrapping test transaction. */
class MainReportSyncLockTest extends TestCase
{
    private MainReportSyncLock $lock;
    private string $table;
    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Real MySQL session exclusion runs in the required MySQL CI job.');
        }
        $this->lock = app(MainReportSyncLock::class);
        $this->table = 'main_sync_test_'.bin2hex(random_bytes(6));
        DB::statement('CREATE TABLE '.$this->table.' (marker VARCHAR(64) PRIMARY KEY) ENGINE=InnoDB');
    }

    protected function tearDown(): void
    {
        if (isset($this->lock)) {
            try { $this->lock->release(); } catch (MainReportSyncLockException) {}
            foreach ($this->workers as [$process, $pipes]) {
                if (is_resource($process)) {
                    if (proc_get_status($process)['running']) proc_terminate($process, 9);
                    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
                    proc_close($process);
                }
            }
            DB::statement('DROP TABLE IF EXISTS '.$this->table);
        }
        parent::tearDown();
    }

    public function test_real_workers_exclude_overlap_after_scheduler_throttle_expires_and_recover_after_sigkill(): void
    {
        $owner = $this->worker();
        $this->assertSame('acquired', $this->receive($owner)['state']);
        // Scheduler cache TTL is not the ownership clock: an arbitrarily old
        // logical scheduler lease cannot displace a still-live MySQL owner.
        $this->travel(11)->minutes();
        for ($i = 0; $i < 3; $i++) {
            $contender = $this->worker();
            $this->assertSame('busy', $this->receive($contender)['state']);
            $this->send($owner, 'probe');
            $this->assertSame('owned', $this->receive($owner)['state']);
        }
        proc_terminate($this->workers[$owner][0], 9); // no finally/destructor cleanup
        $deadline = microtime(true) + 10;
        do {
            usleep(50000);
            $acquired = $this->lock->acquire();
        } while (! $acquired && microtime(true) < $deadline);
        $this->assertTrue($acquired, 'A killed process must not strand the database lock.');
        $this->lock->release();
        $this->assertSame(0, DB::table($this->table)->count());
    }

    public function test_killed_database_session_cannot_write_a_late_provider_response_after_replacement_acquires(): void
    {
        $owner = $this->worker();
        $identity = $this->receive($owner);
        $this->assertSame('acquired', $identity['state']);
        DB::connection()->getPdo()->exec('KILL CONNECTION '.(int) $identity['session']);
        $this->assertTrue($this->lock->acquire());
        $this->send($owner, 'late-write');
        $this->assertSame('lost', $this->receive($owner)['state']);
        $this->assertSame(0, DB::table($this->table)->count());
        $this->lock->release();
    }

    public function test_idle_session_expiry_releases_owner_and_late_response_is_rejected(): void
    {
        $owner = $this->worker();
        $this->assertSame('acquired', $this->receive($owner)['state']);
        $this->send($owner, 'expire');
        $this->assertSame('expiring', $this->receive($owner)['state']);
        sleep(2);
        $this->assertTrue($this->lock->acquire());
        $this->send($owner, 'late-write');
        $this->assertSame('lost', $this->receive($owner)['state']);
        $this->assertSame(0, DB::table($this->table)->count());
        $this->lock->release();
    }

    public function test_active_queries_keep_a_long_running_session_alive_without_reacquiring(): void
    {
        $this->assertTrue($this->lock->acquire());
        $pdo = DB::connection()->getPdo();
        $session = $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
        $pdo->exec('SET SESSION wait_timeout = 2');
        // Run longer than this deliberately shortened idle timeout, with activity.
        for ($i = 0; $i < 7; $i++) {
            usleep(500000);
            DB::select('SELECT 1');
        }
        $this->assertEquals($session, $pdo->query('SELECT CONNECTION_ID()')->fetchColumn());
        $this->lock->release();
    }

    public function test_healthy_release_restores_timeout_reconnector_and_allows_another_run(): void
    {
        $connection = DB::connection();
        $original = (fn () => $this->reconnector)->call($connection);
        $timeout = (int) $connection->getPdo()->query('SELECT @@SESSION.wait_timeout')->fetchColumn();
        for ($run = 0; $run < 2; $run++) {
            $this->assertTrue($this->lock->acquire());
            $this->assertSame(MainReportSyncLock::IDLE_TIMEOUT_SECONDS, (int) $connection->getPdo()->query('SELECT @@SESSION.wait_timeout')->fetchColumn());
            DB::transaction(fn () => DB::table($this->table)->insertOrIgnore(['marker' => 'one-snapshot']));
            $this->lock->assertOwned(); // commits do not release GET_LOCK
            $this->lock->release();
            $this->assertSame($original, (fn () => $this->reconnector)->call($connection));
            $this->assertSame($timeout, (int) $connection->getPdo()->query('SELECT @@SESSION.wait_timeout')->fetchColumn());
        }
        $this->assertSame(1, DB::table($this->table)->count());
        $connection->disconnect();
        $this->assertCount(1, DB::select('SELECT 1')); // original reconnect works again
    }

    public function test_recursion_does_not_acquire_an_extra_mysql_lock_reference(): void
    {
        $this->assertTrue($this->lock->acquire());
        $this->rejects(fn () => $this->lock->acquire());
        $this->lock->assertOwned();
        $this->lock->release();
        $next = $this->worker();
        $this->assertSame('acquired', $this->receive($next)['state']);
        $this->send($next, 'release');
        $this->assertSame('released', $this->receive($next)['state']);
    }

    public function test_lost_ownership_blocks_queries_transactions_and_remains_poisoned_after_reacquisition(): void
    {
        $this->assertTrue($this->lock->acquire());
        $pdo = DB::connection()->getPdo();
        $key = $this->key();
        $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $statement->execute([$key]);
        $this->rejects(fn () => DB::table($this->table)->insert(['marker' => 'forbidden']));
        // Even accidental raw reacquisition cannot revive this worker.
        $statement = $pdo->prepare('SELECT GET_LOCK(?, 0)'); $statement->execute([$key]);
        $this->rejects(fn () => DB::beginTransaction());
        $this->rejects(fn () => $this->lock->assertOwned());
        $this->rejects(fn () => $this->lock->release());
        $this->assertSame(0, DB::table($this->table)->count());
    }

    public function test_ownership_loss_at_commit_rolls_back_raw_pdo_even_when_laravel_transaction_level_is_zero(): void
    {
        $this->assertTrue($this->lock->acquire());
        $pdo = DB::connection()->getPdo();
        $key = $this->key();
        $this->rejects(function () use ($pdo, $key): void {
            DB::transaction(function () use ($pdo, $key): void {
                DB::table($this->table)->insert(['marker' => 'uncommitted']);
                $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $statement->execute([$key]);
            });
        });
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue($pdo->inTransaction());
        $this->rejects(fn () => $this->lock->release());
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(0, DB::table($this->table)->count());
    }

    public function test_disconnect_explicit_reconnect_and_purge_cannot_escape_the_guard(): void
    {
        foreach (['disconnect', 'reconnect', 'purge'] as $action) {
            $this->assertTrue($this->lock->acquire());
            $this->rejects(function () use ($action): void {
                DB::{$action}();
                DB::table($this->table)->insert(['marker' => $action]);
            });
            $this->rejects(fn () => $this->lock->release());
            $this->assertSame(0, DB::table($this->table)->count());
        }
    }

    public function test_preexisting_recursive_session_ownership_is_rejected_without_releasing_unknown_hold(): void
    {
        $pdo = DB::connection()->getPdo();
        $key = $this->key();
        $statement = $pdo->prepare('SELECT GET_LOCK(?, 0)'); $statement->execute([$key]);
        try {
            $this->rejects(fn () => $this->lock->acquire());
            $owner = $pdo->prepare('SELECT IS_USED_LOCK(?)'); $owner->execute([$key]);
            $this->assertEquals($pdo->query('SELECT CONNECTION_ID()')->fetchColumn(), $owner->fetchColumn());
        } finally {
            $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $statement->execute([$key]);
        }
        $next = $this->worker();
        $this->assertSame('acquired', $this->receive($next)['state']);
        $this->send($next, 'release');
        $this->assertSame('released', $this->receive($next)['state']);
    }

    public function test_swapped_read_pdo_cannot_continue_the_guarded_command(): void
    {
        $this->assertTrue($this->lock->acquire());
        $connection = DB::connection();
        $connection->setReadPdo(fn () => $connection->getPdo());
        $this->rejects(fn () => DB::select('SELECT 1'));
        $this->rejects(fn () => $this->lock->release());
        $this->assertSame(0, DB::table($this->table)->count());
    }

    public function test_ambiguous_acquire_response_cleans_the_pinned_session_without_reconnecting(): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [MainSyncFaultStatement::class]);
        MainSyncFaultStatement::$failAfterAcquire = true;
        try {
            $this->rejects(fn () => $this->lock->acquire());
            $this->assertNull($connection->getRawPdo(), 'An uncertain acquisition discards its session.');
            $this->assertSame(120, (int) $pdo->query('SELECT @@SESSION.wait_timeout')->fetchColumn());
        } finally {
            MainSyncFaultStatement::$failAfterAcquire = false;
        }
        $next = $this->worker();
        $this->assertSame('acquired', $this->receive($next)['state']);
        $this->send($next, 'release');
        $this->assertSame('released', $this->receive($next)['state']);
    }

    public function test_release_response_failure_is_observable_and_does_not_retain_a_guard(): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [MainSyncFaultStatement::class]);
        $this->assertTrue($this->lock->acquire());
        MainSyncFaultStatement::$failAfterRelease = true;
        try {
            $this->rejects(fn () => $this->lock->release());
            $this->assertNull($connection->getRawPdo());
            $this->assertSame(120, (int) $pdo->query('SELECT @@SESSION.wait_timeout')->fetchColumn(), 'Uncertain release must not restore a long idle lease.');
        } finally {
            MainSyncFaultStatement::$failAfterRelease = false;
        }
        $this->assertTrue($this->lock->acquire());
        $this->lock->release();
    }

    private function key(): string
    {
        $connection = DB::connection();
        return MainReportSyncLock::keyFor((string) $connection->getPdo()->query('SELECT DATABASE()')->fetchColumn(), $connection->getTablePrefix());
    }

    private function rejects(callable $callback): void
    {
        try { $callback(); } catch (MainReportSyncLockException) { $this->addToAssertionCount(1); return; }
        $this->fail('Expected a fail-closed main reporting lock exception.');
    }

    private function worker(): int
    {
        $environment = array_merge(getenv(), ['APP_ENV' => 'testing', 'HORUS_LOCK_TEST_CONNECTION' => json_encode(DB::connection()->getConfig(), JSON_THROW_ON_ERROR)]);
        $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/main-report-sync-worker.php'), $this->table],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $environment);
        $this->assertIsResource($process);
        stream_set_timeout($pipes[1], 15);
        $this->workers[] = [$process, $pipes];
        return array_key_last($this->workers);
    }

    private function send(int $worker, string $command): void
    {
        fwrite($this->workers[$worker][1][0], $command."\n");
        fflush($this->workers[$worker][1][0]);
    }

    private function receive(int $worker): array
    {
        $line = fgets($this->workers[$worker][1][1]);
        $this->assertNotFalse($line, 'Synthetic worker failed or timed out.');
        return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    }
}

/** Real MySQL performs the operation; only the result handoff is fault-injected. */
class MainSyncFaultStatement extends \PDOStatement
{
    public static bool $failAfterAcquire = false;
    public static bool $failAfterRelease = false;

    public function fetchColumn(int $column = 0): mixed
    {
        $result = parent::fetchColumn($column);
        if (self::$failAfterAcquire && str_contains($this->queryString, 'GET_LOCK(')) {
            self::$failAfterAcquire = false;
            throw new \RuntimeException('Synthetic lost acquisition response.');
        }
        if (self::$failAfterRelease && str_contains($this->queryString, 'RELEASE_LOCK(')) {
            self::$failAfterRelease = false;
            throw new \RuntimeException('Synthetic lost release response.');
        }
        return $result;
    }
}
