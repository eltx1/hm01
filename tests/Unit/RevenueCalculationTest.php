<?php

namespace Tests\Unit;

use App\Models\RevenueRuleVersion;
use App\Services\Reporting\RevenueCalculator;
use PHPUnit\Framework\TestCase;

class RevenueCalculationTest extends TestCase
{
    public function test_financial_formula_uses_minor_units_and_exact_share_remainder(): void
    {
        $rule = new RevenueRuleVersion([
            'publisher_share_bp' => 6500,
            'horus_share_bp' => 2500,
            'mcm_partner_share_bp' => 1000,
        ]);

        $result = (new RevenueCalculator)->calculate(
            grossRevenueMinor: 100000,
            demandPartnerDeductionsMinor: 10000,
            invalidTrafficAdjustmentsMinor: 5000,
            otherAdjustmentsMinor: 2500,
            rule: $rule,
        );

        $this->assertSame(82500, $result['net_revenue_minor']);
        $this->assertSame(53625, $result['publisher_earnings_minor']);
        $this->assertSame(20625, $result['horus_earnings_minor']);
        $this->assertSame(8250, $result['mcm_partner_earnings_minor']);
        $this->assertSame($result['net_revenue_minor'], $result['publisher_earnings_minor'] + $result['horus_earnings_minor'] + $result['mcm_partner_earnings_minor']);
    }

    public function test_fill_ctr_ecpm_and_cpc_rates_are_deterministic(): void
    {
        $rates = (new RevenueCalculator)->rates([
            'ad_requests' => 1000,
            'matched_requests' => 800,
            'impressions' => 750,
            'clicks' => 15,
            'gross_revenue_minor' => 3000,
        ]);

        $this->assertSame(8000, $rates['fill_rate_bp']);
        $this->assertSame(200, $rates['ctr_bp']);
        $this->assertSame(40000, $rates['ecpm_micros']);
        $this->assertSame(200000, $rates['cpc_micros']);
    }
}
