<?php

namespace Tests\Unit;

use App\Services\Reporting\SiteGamUnfilledSynchronizer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SiteGamUnfilledCsvTest extends TestCase
{
    private const HEADER = "Dimension.DATE,Dimension.AD_UNIT_ID,Column.TOTAL_INVENTORY_LEVEL_UNFILLED_IMPRESSIONS\n";

    public function test_original_counter_distinguishes_reported_zero_from_absent_days(): void
    {
        $this->assertSame(['2026-10-01' => 0, '2026-10-03' => 19], SiteGamUnfilledSynchronizer::parse(
            self::HEADER."2026-10-01,123,0\n2026-10-03,123,19\n", '123', '2026-10-01', '2026-10-03'));
        $this->assertSame([], SiteGamUnfilledSynchronizer::parse(self::HEADER, '123', '2026-10-01', '2026-10-03'));
    }

    public function test_invalid_or_wrong_scope_csv_fails_closed(): void
    {
        foreach ([
            self::HEADER."2026-10-01,999,1\n", self::HEADER."2026-10-04,123,1\n",
            self::HEADER."2026-10-01,123,-1\n", self::HEADER."2026-10-01,123,1.2\n",
            self::HEADER."2026-10-01,123,1000000000000000\n", self::HEADER."2026-10-01,123,0\n2026-10-01,123,1\n",
            str_replace('TOTAL_INVENTORY_LEVEL_UNFILLED_IMPRESSIONS', 'TOTAL_UNMATCHED_AD_REQUESTS', self::HEADER),
            str_replace("\n", ",Dimension.SITE_NAME\n", self::HEADER)."2026-10-01,123,1,example.test\n",
            self::HEADER."2026-10-01,123\n",
        ] as $csv) {
            try { SiteGamUnfilledSynchronizer::parse($csv, '123', '2026-10-01', '2026-10-03'); $this->fail('Invalid evidence accepted'); }
            catch (RuntimeException) { $this->addToAssertionCount(1); }
        }
    }
}
