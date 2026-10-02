<?php

namespace App\Services\Reporting;

/** The Google Ad Exchange basis used by website-bound reporting only. */
final class SiteGamReportMetrics
{
    public const BASIS = 'AD_EXCHANGE_V1';

    public const FINANCE_COLUMNS = [
        'AD_EXCHANGE_LINE_ITEM_LEVEL_IMPRESSIONS' => 'impressions',
        'AD_EXCHANGE_LINE_ITEM_LEVEL_CLICKS' => 'clicks',
        'AD_EXCHANGE_LINE_ITEM_LEVEL_REVENUE' => 'revenue_micros',
    ];

    public const CORE_COLUMNS = [
        'AD_EXCHANGE_TOTAL_REQUESTS' => 'ad_requests',
        'AD_EXCHANGE_RESPONSES_SERVED' => 'matched_requests',
        ...self::FINANCE_COLUMNS,
    ];

    public const OPTIONAL_COLUMNS = [
        'AD_EXCHANGE_ACTIVE_VIEW_VIEWABLE_IMPRESSIONS' => 'active_view_viewable_impressions',
        'AD_EXCHANGE_ACTIVE_VIEW_MEASURABLE_IMPRESSIONS' => 'active_view_measurable_impressions',
    ];

    public const COLUMNS = [...self::CORE_COLUMNS, ...self::OPTIONAL_COLUMNS];
}
