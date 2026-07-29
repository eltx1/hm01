<?php

namespace App\Services\Reporting;

use App\Models\RevenueRuleVersion;

final class RevenueCalculator
{
    public function calculate(
        int $grossRevenueMinor,
        int $demandPartnerDeductionsMinor,
        int $invalidTrafficAdjustmentsMinor,
        int $otherAdjustmentsMinor,
        RevenueRuleVersion $rule,
    ): array {
        $net = max(
            0,
            $grossRevenueMinor
            - $demandPartnerDeductionsMinor
            - $invalidTrafficAdjustmentsMinor
            - $otherAdjustmentsMinor
        );

        $publisher = intdiv($net * (int) $rule->publisher_share_bp, 10000);
        $mcm = intdiv($net * (int) $rule->mcm_partner_share_bp, 10000);
        $horus = $net - $publisher - $mcm;

        return [
            'gross_revenue_minor' => $grossRevenueMinor,
            'demand_partner_deductions_minor' => $demandPartnerDeductionsMinor,
            'invalid_traffic_adjustments_minor' => $invalidTrafficAdjustmentsMinor,
            'other_adjustments_minor' => $otherAdjustmentsMinor,
            'net_revenue_minor' => $net,
            'publisher_earnings_minor' => $publisher,
            'horus_earnings_minor' => $horus,
            'mcm_partner_earnings_minor' => $mcm,
        ];
    }

    public function rates(array $metrics): array
    {
        $requests = max(0, (int) ($metrics['ad_requests'] ?? 0));
        $matched = max(0, (int) ($metrics['matched_requests'] ?? 0));
        $impressions = max(0, (int) ($metrics['impressions'] ?? 0));
        $clicks = max(0, (int) ($metrics['clicks'] ?? 0));
        $gross = max(0, (int) ($metrics['gross_revenue_minor'] ?? $metrics['revenue_minor'] ?? 0));

        return [
            'fill_rate_bp' => $requests > 0 ? min(10000, (int) round($matched * 10000 / $requests)) : 0,
            'ctr_bp' => $impressions > 0 ? min(10000, (int) round($clicks * 10000 / $impressions)) : 0,
            'ecpm_micros' => $impressions > 0 ? (int) round(($gross * 10000) / $impressions) : 0,
            'cpc_micros' => $clicks > 0 ? (int) round(($gross * 1000) / $clicks) : 0,
        ];
    }
}
