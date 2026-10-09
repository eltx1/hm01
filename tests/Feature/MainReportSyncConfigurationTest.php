<?php

namespace Tests\Feature;

use App\Services\Reporting\MainReportSyncLock;
use App\Services\Reporting\MainReportSyncLockException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MainReportSyncConfigurationTest extends TestCase
{
    public function test_unsupported_database_topologies_fail_before_connecting_or_running_provider_work(): void
    {
        $default = DB::getDefaultConnection();
        $base = config('database.connections.mysql');
        foreach ([
            ['driver' => 'sqlite', 'database' => ':memory:'],
            array_replace($base, ['host' => ['writer-a.invalid', 'writer-b.invalid']]),
            array_replace($base, ['read' => ['host' => 'reader.invalid'], 'write' => ['host' => 'writer.invalid']]),
        ] as $index => $configuration) {
            $name = 'unsupported_main_sync_'.$index;
            config(['database.connections.'.$name => $configuration]);
            DB::setDefaultConnection($name);
            try {
                app(MainReportSyncLock::class)->acquire();
                $this->fail('Unsupported topology must not start a main reporting run.');
            } catch (MainReportSyncLockException $exception) {
                $this->assertStringContainsString('single MySQL writer', $exception->getMessage());
                $this->assertInstanceOf(\Closure::class, DB::connection()->getRawPdo(), 'No unsupported remote host should be contacted.');
            } finally {
                DB::purge($name);
                DB::setDefaultConnection($default);
            }
        }
    }
}
