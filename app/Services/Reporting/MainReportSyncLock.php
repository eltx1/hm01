<?php

namespace App\Services\Reporting;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\TransactionCommitting;
use PDO;
use Throwable;
use WeakMap;

/**
 * Main-command exclusion on the same session that writes its financial data.
 * Requires one direct, non-multiplexed MySQL writer shared by all app hosts.
 * The scheduler cache lock is only a short-lived launch throttle, not this fence.
 */
class MainReportSyncLock
{
    public const IDLE_TIMEOUT_SECONDS = 120;

    private ?Connection $connection = null;
    private ?PDO $pdo = null;
    private ?string $name = null;
    private ?string $key = null;
    private ?string $session = null;
    private ?int $previousTimeout = null;
    private mixed $previousReconnector = null;
    private bool $active = false;
    private bool $lost = false;
    private bool $acquired = false;
    private bool $attempted = false;
    private WeakMap $hooked;

    public function __construct(private readonly DatabaseManager $database, Dispatcher $events)
    {
        $this->hooked = new WeakMap;
        $events->listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            $this->installHooks($event->connection);
            if ($this->active) $this->assertOwned($event->connection);
        });
        $events->listen(TransactionCommitting::class, function (TransactionCommitting $event): void {
            $this->assertOwned($event->connection);
        });
    }

    public static function keyFor(string $database, string $prefix): string
    {
        // Deliberately independent of host, connection alias, APP_KEY and deploy path.
        return 'horus:main-sync:'.substr(hash('sha256', $database.'|'.$prefix), 0, 48);
    }

    /** False means a live owner already holds the lock; never wait or steal it. */
    public function acquire(): bool
    {
        if ($this->active) throw new MainReportSyncLockException('Main reporting lock cannot be acquired recursively.');

        try {
            $connection = $this->database->connection();
            if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)
                || $connection->getConfig('read') || $connection->getConfig('write')
                || is_array($connection->getConfig('host')) || $connection->transactionLevel() !== 0) {
                throw new MainReportSyncLockException('Main reporting requires a single MySQL writer without an existing transaction or read/write routing.');
            }
            $pdo = $connection->getPdo();
            if ($pdo->getAttribute(PDO::ATTR_PERSISTENT) || $pdo->inTransaction()
                || ($connection->getRawReadPdo() !== null && $connection->getRawReadPdo() !== $pdo)) {
                throw new MainReportSyncLockException('Main reporting requires a nonpersistent, idle MySQL session.');
            }
            $identity = $pdo->query('SELECT CONNECTION_ID() AS session_id, DATABASE() AS database_name, @@SESSION.wait_timeout AS idle_timeout, @@GLOBAL.read_only AS read_only')->fetch(PDO::FETCH_ASSOC);
            if (! is_array($identity) || empty($identity['database_name']) || (int) $identity['read_only'] !== 0) {
                throw new MainReportSyncLockException('Main reporting requires the authoritative writable database.');
            }
            // Laravel exposes a setter but no getter. Capture before GET_LOCK so
            // failure here cannot leave an untracked server-side lock behind.
            $reconnector = (fn () => $this->reconnector)->call($connection);
            if (! is_callable($reconnector)) throw new MainReportSyncLockException('Main reporting requires a restorable database connection.');
            $key = self::keyFor((string) $identity['database_name'], $connection->getTablePrefix());
            $owner = $pdo->prepare('SELECT IS_USED_LOCK(?)');
            $owner->execute([$key]);
            if ((string) $owner->fetchColumn() === (string) $identity['session_id']) {
                throw new MainReportSyncLockException('Main reporting refuses an already-owned session lock.');
            }
            $this->connection = $connection;
            $this->pdo = $pdo;
            $this->name = $this->database->getDefaultConnection();
            $this->key = $key;
            $this->session = (string) $identity['session_id'];
            $this->previousTimeout = (int) $identity['idle_timeout'];
            $this->previousReconnector = $reconnector;
            $this->lost = false;
            $this->active = true;
            foreach ($this->database->getConnections() as $existing) $this->installHooks($existing);
            $connection->setReconnector(function (): never {
                $this->fail('Main reporting database session was lost; reconnect is disabled until this worker stops.');
            });
            // Bound even the ambiguous acquire-response window before GET_LOCK.
            $pdo->exec('SET SESSION wait_timeout = '.self::IDLE_TIMEOUT_SECONDS);
            if ((int) $pdo->query('SELECT @@SESSION.wait_timeout')->fetchColumn() !== self::IDLE_TIMEOUT_SECONDS) {
                $this->fail('Main reporting could not bound its database session lifetime.');
            }
            $this->attempted = true;
            $statement = $pdo->prepare('SELECT GET_LOCK(?, 0)');
            $statement->execute([$this->key]);
            $acquired = $statement->fetchColumn();
            if ((string) $acquired === '0') {
                $this->attempted = false;
                $this->release();
                return false;
            }
            if ((string) $acquired !== '1') $this->fail('Main reporting lock could not be acquired safely.');
            $this->acquired = true;
            $this->assertOwned();

            return true;
        } catch (Throwable $exception) {
            try { $this->release(); } catch (MainReportSyncLockException) {}
            if ($exception instanceof MainReportSyncLockException) throw $exception;
            throw new MainReportSyncLockException('Main reporting lock is unavailable; synchronization stopped safely.', 0, $exception);
        }
    }

    /** Inactive outside this command, including the independent Video workflow. */
    public function assertOwned(?Connection $connection = null): void
    {
        if (! $this->active) return;
        if ($this->lost) throw new MainReportSyncLockException('Main reporting lock was lost; this worker cannot continue.');
        if (($connection !== null && $connection !== $this->connection)
            || $this->database->getDefaultConnection() !== $this->name
            || ($this->database->getConnections()[$this->name] ?? null) !== $this->connection
            || $this->connection->getRawPdo() !== $this->pdo
            || ($this->connection->getRawReadPdo() !== null && $this->connection->getRawReadPdo() !== $this->pdo)) {
            $this->fail('Main reporting database session changed; this worker cannot continue.');
        }
        try {
            // Use raw pinned PDO, never Laravel's reconnecting query path.
            $statement = $this->pdo->prepare('SELECT CONNECTION_ID() AS session_id, IS_USED_LOCK(?) AS owner_id');
            $statement->execute([$this->key]);
            $owner = $statement->fetch(PDO::FETCH_ASSOC);
            if (! is_array($owner) || (string) $owner['session_id'] !== $this->session || (string) $owner['owner_id'] !== $this->session) {
                $this->fail('Main reporting lock ownership changed; this worker cannot continue.');
            }
        } catch (Throwable $exception) {
            $this->lost = true;
            throw new MainReportSyncLockException('Main reporting database ownership could not be verified; synchronization stopped safely.', 0, $exception);
        }
    }

    /** Roll back first, then release only our session's lock. Never force-unlock. */
    public function release(): void
    {
        if (! $this->active) return;
        $failed = $this->lost;
        try {
            // A TransactionCommitting listener exception can leave PDO in a
            // transaction even after Laravel decrements its transaction counter.
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
                $failed = true;
            }
            if ($this->attempted) {
                $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
                $statement->execute([$this->key]);
                if ((string) $statement->fetchColumn() !== '1' || ! $this->acquired) $failed = true;
            }
            // Never restore a long timeout while release is uncertain: a lost
            // network response must leave any abandoned server-side hold bounded.
            if (! $failed) $this->pdo->exec('SET SESSION wait_timeout = '.$this->previousTimeout);
        } catch (Throwable) {
            $failed = true;
        } finally {
            $this->connection->setReconnector($this->previousReconnector);
            // A failed/lost session must never leak into a later in-process command.
            if ($failed) $this->connection->disconnect();
            $this->active = false;
            $this->lost = $this->acquired = $this->attempted = false;
            $this->connection = null;
            $this->pdo = null;
            $this->name = $this->key = $this->session = null;
            $this->previousTimeout = null;
            $this->previousReconnector = null;
        }
        if ($failed) throw new MainReportSyncLockException('Main reporting stopped after database ownership or cleanup failed.');
    }

    private function installHooks(Connection $connection): void
    {
        if (isset($this->hooked[$connection])) return;
        $this->hooked[$connection] = true;
        $connection->beforeExecuting(fn ($query, $bindings, Connection $database) => $this->assertOwned($database));
        $connection->beforeStartingTransaction(fn (Connection $database) => $this->assertOwned($database));
    }

    private function fail(string $message): never
    {
        $this->lost = true;
        throw new MainReportSyncLockException($message);
    }
}
