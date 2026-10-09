<?php

namespace Tests\Unit;

use App\Services\Reporting\MainReportSyncLock;
use PHPUnit\Framework\TestCase;

class MainReportSyncLockKeyTest extends TestCase
{
    public function test_key_is_database_and_table_namespace_scoped_and_fits_mysql_limit(): void
    {
        $key = MainReportSyncLock::keyFor('horus', '');
        $this->assertSame($key, MainReportSyncLock::keyFor('horus', ''));
        $this->assertNotSame($key, MainReportSyncLock::keyFor('other_database', ''));
        $this->assertNotSame($key, MainReportSyncLock::keyFor('horus', 'test_'));
        $this->assertLessThanOrEqual(64, strlen(MainReportSyncLock::keyFor(str_repeat('d', 256), str_repeat('p', 256))));
        $this->assertSame(120, MainReportSyncLock::IDLE_TIMEOUT_SECONDS);
    }
}
