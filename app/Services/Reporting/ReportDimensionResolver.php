<?php

namespace App\Services\Reporting;

use App\Models\Advertiser;
use App\Models\Campaign;
use App\Models\Publisher;
use App\Models\ReportDimension;
use App\Models\Site;

final class ReportDimensionResolver
{
    private const KEYS = [
        'publisher_id', 'site_id', 'placement_id', 'gam_connection_id',
        'demand_network_id', 'bidder_id', 'advertiser_id', 'campaign_id',
        'country_code', 'device', 'browser', 'operating_system', 'ad_size',
    ];

    public function resolve(array $row, ?string $fallbackOrganizationId = null): ReportDimension
    {
        $attributes = [];
        foreach (self::KEYS as $key) {
            $value = $row[$key] ?? null;
            $attributes[$key] = is_scalar($value) && (string) $value !== '' ? (string) $value : null;
        }
        if ($attributes['country_code']) {
            $attributes['country_code'] = strtoupper(substr($attributes['country_code'], 0, 2));
        }

        $organizationId = $row['organization_id'] ?? null;
        if ($attributes['site_id']) {
            $site = Site::withoutGlobalScopes()->find($attributes['site_id']);
            $attributes['publisher_id'] ??= $site?->publisher_id;
            $organizationId ??= $site?->organization_id;
        }
        if ($attributes['publisher_id']) {
            $organizationId ??= Publisher::withoutGlobalScopes()->find($attributes['publisher_id'])?->organization_id;
        }
        if ($attributes['campaign_id']) {
            $campaign = Campaign::withoutGlobalScopes()->find($attributes['campaign_id']);
            $attributes['advertiser_id'] ??= $campaign?->advertiser_id;
            $organizationId ??= $campaign?->organization_id;
        }
        if ($attributes['advertiser_id']) {
            $organizationId ??= Advertiser::withoutGlobalScopes()->find($attributes['advertiser_id'])?->organization_id;
        }
        $organizationId ??= $fallbackOrganizationId;

        $external = collect($row)
            ->except(array_merge(self::KEYS, [
                'organization_id', 'date', 'report_date', 'hour', 'report_hour',
                'currency', 'ad_requests', 'matched_requests', 'unfilled_requests',
                'impressions', 'clicks', 'fill_rate', 'ctr', 'viewability',
                'viewability_bp', 'revenue', 'revenue_minor', 'gross_revenue_minor',
                'demand_partner_deductions_minor', 'invalid_traffic_adjustments_minor',
                'other_adjustments_minor', 'video_starts', 'completed_views',
                'spend_minor', 'remaining_budget_minor', 'external_report_id',
            ]))
            ->filter(fn ($value) => is_scalar($value) || is_array($value))
            ->sortKeys()
            ->all();

        $hashPayload = $attributes;
        $hashPayload['external_dimensions'] = $external;
        ksort($hashPayload);
        $hash = hash('sha256', json_encode($hashPayload, JSON_THROW_ON_ERROR));

        return ReportDimension::withoutGlobalScopes()->firstOrCreate(
            ['dimension_hash' => $hash],
            array_merge($attributes, [
                'organization_id' => $organizationId,
                'external_dimensions' => $external ?: null,
            ]),
        );
    }
}
