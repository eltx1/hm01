<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\ConfigVersion;
use App\Models\Site;
use App\Models\User;
use App\Services\Inventory\SiteConfigPublisher;
use Database\Seeders\InventoryDeliverySeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

/** Two real connections/processes; SQLite cannot establish row-lock behavior. */
class ConfigurationRecoveryConcurrencyTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires isolated MySQL and pcntl.');
        }
        $this->assertSame('testing', app()->environment());
        $this->assertStringEndsWith('_test', (string) DB::connection()->getDatabaseName());
        RefreshDatabaseState::$migrated = false;
        $this->beforeApplicationDestroyed(function (): void {
            while (DB::transactionLevel() > 0) DB::rollBack();
            $this->artisan('db:wipe', ['--drop-views' => true, '--force' => true])->assertExitCode(0);
            RefreshDatabaseState::$migrated = false;
        });
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    public static function writers(): array
    {
        return [['publish'], ['recover']];
    }

    #[DataProvider('writers')]
    public function test_site_lock_serializes_publication_and_recovery_versions(string $childOperation): void
    {
        $this->seedIdentity();
        $this->seed(InventoryDeliverySeeder::class);
        $actor = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $owner = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($owner), $owner);
        $site->update(['status' => 'ACTIVE', 'serving_mode' => 'HORUS_DIRECT']);
        $site->servingSettings()->update(['serving_mode' => 'HORUS_DIRECT']);
        $site->siteConfig()->update(['status' => 'ACTIVE']);
        $publisher = app(SiteConfigPublisher::class);
        $source = $publisher->publish($site->fresh(), ConfigEnvironment::Production, $actor);
        $siteId = $site->id; $sourceId = $source->id; $actorId = $actor->id;
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        // Do not share an inherited PDO socket across forked processes.
        DB::disconnect();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            stream_set_timeout($sockets[1], 15);
            $success = false;
            try {
                if (trim((string) fgets($sockets[1])) !== 'GO') exit(2);
                DB::reconnect();
                DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
                fwrite($sockets[1], "STARTED\n");
                $service = app(SiteConfigPublisher::class);
                $childSite = Site::withoutGlobalScopes()->findOrFail($siteId);
                $childActor = User::findOrFail($actorId);
                $version = $childOperation === 'publish'
                    ? $service->publish($childSite, ConfigEnvironment::Production, $childActor)
                    : $service->rollback($childSite, ConfigEnvironment::Production, ConfigVersion::withoutGlobalScopes()->findOrFail($sourceId), $childActor);
                fwrite($sockets[1], $version->version."\n");
                $success = true;
            } catch (\Throwable $error) {
                fwrite($sockets[1], "FAILED:".get_class($error).":".basename($error->getFile()).":".$error->getLine()."\n");
            }
            DB::disconnect();
            fclose($sockets[1]);
            exit($success ? 0 : 1);
        }
        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 15);
        $status = null;
        try {
            DB::reconnect();
            DB::beginTransaction();
            Site::withoutGlobalScopes()->whereKey($siteId)->lockForUpdate()->firstOrFail();
            fwrite($sockets[0], "GO\n");
            $this->assertSame('STARTED', trim((string) fgets($sockets[0])));
            // Child has begun its operation but must wait for this site lock.
            $read = [$sockets[0]]; $write = null; $except = null;
            $this->assertSame(0, stream_select($read, $write, $except, 0, 200000));
            $parent = $childOperation === 'publish'
                ? $publisher->rollback($site->fresh(), ConfigEnvironment::Production, $source->fresh(), $actor)
                : $publisher->publish($site->fresh(), ConfigEnvironment::Production, $actor);
            $this->assertSame(2, $parent->version);
            DB::commit();
            $this->assertSame('3', trim((string) fgets($sockets[0])));
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertSame([1, 2, 3], ConfigVersion::withoutGlobalScopes()->where('site_id', $siteId)->orderBy('version')->pluck('version')->all());
        } finally {
            while (DB::transactionLevel() > 0) DB::rollBack();
            fclose($sockets[0]);
            if ($status === null) pcntl_waitpid($pid, $status);
        }
    }
}
