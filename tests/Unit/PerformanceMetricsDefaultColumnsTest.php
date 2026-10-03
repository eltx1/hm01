<?php

namespace Tests\Unit;

use App\Services\Reporting\PerformanceMetrics;
use PHPUnit\Framework\TestCase;

class PerformanceMetricsDefaultColumnsTest extends TestCase
{
    public function test_true_unfilled_impressions_are_default_for_every_source_mix(): void
    {
        $base = ['impressions', 'clicks', 'ctr_bp', 'ecpm_minor', 'viewability_bp', 'unfilled_impressions'];
        foreach ([[], ['has_other_sources' => true]] as $totals) {
            $this->assertSame($base, PerformanceMetrics::defaultColumns($totals));
        }
        $siteAdExchange = ['impressions', 'clicks', 'ctr_bp', 'ecpm_minor', 'viewability_bp',
            'ad_exchange_unmatched_requests', 'unfilled_impressions'];
        foreach ([['has_site_ad_exchange' => true],
            ['has_site_ad_exchange' => true, 'has_other_sources' => true]] as $totals) {
            $this->assertSame($siteAdExchange, PerformanceMetrics::defaultColumns($totals));
        }
        $this->assertSame('Ad Exchange unmatched requests', PerformanceMetrics::COLUMNS['ad_exchange_unmatched_requests']);
        $this->assertSame('Unfilled impressions', PerformanceMetrics::COLUMNS['unfilled_impressions']);
    }

    public function test_unfilled_null_and_real_zero_remain_distinct(): void
    {
        $this->assertSame('—', PerformanceMetrics::display('unfilled_impressions', null));
        $this->assertSame('0', PerformanceMetrics::display('unfilled_impressions', 0));
        $this->assertSame('1,234', PerformanceMetrics::display('unfilled_impressions', 1234));
    }
}
