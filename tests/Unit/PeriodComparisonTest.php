<?php

namespace Tests\Unit;

use App\Support\PeriodComparison;
use PHPUnit\Framework\TestCase;

class PeriodComparisonTest extends TestCase
{
    public function test_previous_period_has_the_same_length_immediately_before(): void
    {
        $this->assertSame(['from' => '2026-09-19', 'to' => '2026-09-19'], PeriodComparison::previous('2026-09-20', '2026-09-20'));
        $this->assertSame(['from' => '2026-09-08', 'to' => '2026-09-14'], PeriodComparison::previous('2026-09-15', '2026-09-21'));
        $this->assertSame(['from' => '2026-08-03', 'to' => '2026-08-31'], PeriodComparison::previous('2026-09-01', '2026-09-29'));
    }

    public function test_relative_change_and_percentage_points(): void
    {
        $this->assertSame(['direction' => 'up', 'text' => '+100.0%', 'value' => 10000], PeriodComparison::delta('earnings_minor', 8000, 4000));
        $this->assertSame('-25.0%', PeriodComparison::delta('impressions', 75, 100)['text']);
        $this->assertSame('flat', PeriodComparison::delta('impressions', 100, 100)['direction']);
        $this->assertSame('+0.50 pp', PeriodComparison::delta('ctr_bp', 450, 400)['text']);
        // No honest percentage from zero or unavailable data.
        $this->assertNull(PeriodComparison::delta('earnings_minor', 100, 0));
        $this->assertNull(PeriodComparison::delta('ecpm_minor', null, 100));
        $this->assertNull(PeriodComparison::delta('ecpm_minor', 100, null));
    }
}
