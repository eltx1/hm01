<?php

namespace App\Services\Reporting;

use App\Models\DailyReport;
use App\Models\GamRevenueCorrectionReceipt;
use App\Models\HourlyReport;
use App\Models\ReportDimension;
use App\Models\ReportSourceConnection;
use App\Models\SiteGamReportBinding;
use Carbon\CarbonImmutable;

/** Read-only census. Ineligible and inactive history is counted, never silently omitted. */
final class GamHistoricalInventory
{
    public function __construct(private readonly SiteGamReportScope $scopes) {}

    public static function factHash(array $fact, ?array $dimension): string
    {
        return GamRevenueCorrectionService::hash([$fact, $dimension]);
    }

    public static function receiptHash(GamRevenueCorrectionReceipt $receipt): string
    {
        return GamRevenueCorrectionService::hash($receipt->getAttributes());
    }

    public function discover(?string $through = null): array
    {
        $records = $rows = $windows = $reasons = $corrected = [];
        $counts = array_fill_keys(['sources', 'daily_facts', 'hourly_facts', 'windows', 'eligible_windows',
            'blocked_facts', 'corrected_facts', 'forward_facts', 'pending', 'ready', 'applied', 'blocked'], 0);
        // GAM identity can be in the source, connection type, binding, or fact dimension.
        $bindings = SiteGamReportBinding::withoutGlobalScopes()->with(['site', 'connection.source', 'gamConnection'])->orderBy('id')->get();
        $connections = ReportSourceConnection::withoutGlobalScopes()->with('source')->orderBy('id')->get();
        $gamSources = [];
        foreach ($connections as $connection) {
            if (in_array($connection->source?->getRawOriginal('code'), ['HORUS_GAM', 'MCM_PARTNER_GAM', 'PUBLISHER_GAM', 'GAM_AD_UNIT'], true)
                || str_contains($connection->connection_type, 'GAM')
                || $bindings->contains('report_source_connection_id', $connection->id)) $gamSources[$connection->id] = $connection;
        }
        // Resolve dimension-discovered sources before the census, so an older row
        // cannot be skipped merely because a later row supplies the GAM identity.
        foreach (ReportDimension::withoutGlobalScopes()->orderBy('id')->lazy(250) as $dimension) {
            if (! $dimension->gam_connection_id && ! array_filter(array_keys($dimension->external_dimensions ?? []), fn ($key) => str_starts_with($key, 'gam_'))) continue;
            foreach ([DailyReport::class, HourlyReport::class] as $model) {
                foreach ($model::withoutGlobalScopes()->where('report_dimension_id', $dimension->id)->distinct()->pluck('report_source_connection_id') as $sourceId) {
                    if ($connection = $connections->firstWhere('id', $sourceId)) $gamSources[$connection->id] = $connection;
                }
            }
        }
        foreach (GamRevenueCorrectionReceipt::orderBy('id')->cursor() as $receipt) {
            if ($through !== null && ! array_filter($receipt->after['facts'] ?? [],
                fn ($row) => substr($row['fact']['report_date'], 0, 10) <= $through)) continue;
            $records['receipt:'.$receipt->id] = self::receiptHash($receipt);
            foreach ($receipt->after['facts'] ?? [] as $row) {
                $corrected[$row['fact']['id']][] = ['receipt_id' => $receipt->id, 'digest' => $receipt->digest,
                    'hash' => self::factHash($row['fact'], $row['dimension'])];
            }
        }
        foreach (['daily' => DailyReport::class, 'hourly' => HourlyReport::class] as $kind => $model) {
            foreach ($model::withoutGlobalScopes()->with('dimension')->orderBy('id')->lazy(250) as $fact) {
                // A finite authorization cannot absorb facts created for later
                // days while its reviewed operation is running or being resumed.
                if ($through !== null && $fact->report_date->toDateString() > $through) continue;
                $dimension = $fact->dimension;
                $external = $dimension?->external_dimensions ?? [];
                if (! isset($gamSources[$fact->report_source_connection_id]) && ! $dimension?->gam_connection_id
                    && ! array_filter(array_keys($external), fn ($key) => str_starts_with($key, 'gam_'))) continue;
                if ($connection = $connections->firstWhere('id', $fact->report_source_connection_id)) $gamSources[$connection->id] = $connection;
                $counts[$kind.'_facts']++;
                $records[$kind.':'.$fact->id] = self::factHash($fact->getAttributes(), $dimension?->getAttributes());
                $rows[] = ['kind' => $kind, 'fact' => $fact, 'hash' => $records[$kind.':'.$fact->id]];
            }
        }
        foreach ($gamSources as $id => $connection) {
            $records['source:'.$id] = GamRevenueCorrectionService::hash([
                $connection->only(['id', 'organization_id', 'report_source_id', 'connection_type', 'connection_id', 'currency', 'timezone', 'is_enabled', 'status']),
                $connection->source?->only(['id', 'code', 'is_enabled']), data_get($connection->configuration, 'site_report_scope'),
            ]);
        }
        foreach ($bindings as $binding) {
            $records['binding:'.$binding->id] = GamRevenueCorrectionService::hash([$binding->getAttributes(),
                $binding->site?->only(['id', 'organization_id', 'publisher_id', 'primary_domain']),
                $binding->gamConnection?->only(['id', 'organization_id', 'network_code', 'is_enabled', 'type'])]);
        }
        $counts['sources'] = count($gamSources);
        $eligible = [];
        $coverage = [];
        foreach ($rows as $row) {
            $fact = $row['fact'];
            $key = $row['kind'].':'.$fact->id;
            $date = $fact->report_date->toDateString();
            $reason = null;
            $provenance = $row['kind'] === 'daily' ? ($corrected[$fact->id] ?? []) : [];
            if ($provenance) {
                $matching = array_values(array_filter($provenance, fn ($entry) => hash_equals($entry['hash'], $row['hash'])));
                if (count($matching) === 1) {
                    $counts['corrected_facts']++;
                    $coverage[$key] = ['state' => 'CORRECTED', 'provenance' => $matching[0]];
                    continue;
                }
                $reason = 'RECEIPT_MISMATCH';
            }
            $matches = $bindings->where('report_source_connection_id', $fact->report_source_connection_id);
            $binding = $matches->count() === 1 ? $matches->first() : null;
            if (! $reason && ! $binding) $reason = 'UNBOUND_SOURCE';
            if (! $reason && (! $binding->site || ! $binding->active_site_id || $binding->active_site_id !== $binding->site_id
                || ! $binding->connection?->is_enabled || ! $binding->gamConnection?->is_enabled
                || $binding->connection->status->value !== 'ACTIVE' || ! $binding->connection->source?->is_enabled)) $reason = 'INACTIVE_BINDING';
            $scope = $binding ? data_get($binding->connection?->configuration, 'site_report_scope') : null;
            if (! $reason) {
                try {
                    if (! is_array($scope)) throw new \RuntimeException;
                    $this->scopes->assertCurrent($binding, $scope);
                    if ($binding->connection->currency !== 'USD' || $binding->connection->source->getRawOriginal('code') !== 'GAM_AD_UNIT'
                        || ! ctype_digit($binding->ad_unit_id) || $binding->network_code !== (string) $binding->gamConnection->network_code
                        || ! in_array($binding->connection->timezone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)) throw new \RuntimeException;
                } catch (\Throwable) { $reason = 'UNVERIFIED_SCOPE'; }
            }
            if (! $reason && ($fact->organization_id !== $binding->organization_id || $fact->dimension?->site_id !== $binding->site_id
                || $fact->dimension?->organization_id !== $binding->organization_id || $fact->dimension?->publisher_id !== $binding->site->publisher_id
                || $fact->dimension?->gam_connection_id !== $binding->gam_connection_id)) $reason = 'MIXED_IDENTITY';
            if (! $reason && $date >= $scope['effective_from']) {
                $external = $fact->dimension?->external_dimensions ?? [];
                if (($external['gam_report_scope'] ?? null) === $scope['fingerprint']
                    && ($external['gam_report_basis'] ?? null) === SiteGamReportMetrics::BASIS
                    && ($external['gam_ad_unit_id'] ?? null) === $binding->ad_unit_id
                    && ($external['gam_report_site'] ?? null) === $scope['hostname'] && $fact->currency === 'USD') {
                    $counts['forward_facts']++;
                    $coverage[$key] = ['state' => 'FORWARD_SCOPE'];
                    // Expected ongoing forward imports cannot invalidate historical evidence.
                    unset($records[$key]);
                    continue;
                }
                $reason = 'UNVERIFIED_SCOPE';
            }
            if (! $reason && $row['kind'] === 'hourly') $reason = 'HOURLY_FACTS';
            if (! $reason && ($date < $binding->starts_on->toDateString() || ($binding->ends_on && $date > $binding->ends_on->toDateString()))) $reason = 'OUTSIDE_BINDING';
            if (! $reason && $date >= CarbonImmutable::now($binding->connection->timezone)->toDateString()) $reason = 'INCOMPLETE_DAY';
            if ($reason) {
                $counts['blocked_facts']++;
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                $coverage[$key] = ['state' => 'BLOCKED', 'reason' => $reason];
                continue;
            }
            $group = $binding->id.':'.substr($date, 0, 7);
            $eligible[$group][$date][] = ['id' => $fact->id, 'site_id' => $binding->site_id, 'binding_id' => $binding->id,
                'source_connection_id' => $binding->report_source_connection_id];
        }
        ksort($eligible);
        foreach ($eligible as $dates) {
            ksort($dates);
            $window = null;
            foreach ($dates as $date => $facts) {
                if (count($facts) !== 1) {
                    if ($window) { $windows[] = $window; $window = null; }
                    foreach ($facts as $fact) $coverage['daily:'.$fact['id']] = ['state' => 'BLOCKED', 'reason' => 'MULTIPLE_FACTS'];
                    $counts['blocked_facts'] += count($facts);
                    $reasons['MULTIPLE_FACTS'] = ($reasons['MULTIPLE_FACTS'] ?? 0) + count($facts);
                    continue;
                }
                if ($window && CarbonImmutable::parse($window['to'])->addDay()->toDateString() !== $date) {
                    $windows[] = $window; $window = null;
                }
                $fact = $facts[0];
                if (! $window) $window = array_diff_key($fact, ['id' => true]) + ['from' => $date, 'to' => $date, 'fact_ids' => []];
                $window['to'] = $date;
                $window['fact_ids'][] = $fact['id'];
                $coverage['daily:'.$fact['id']] = ['state' => 'ELIGIBLE'];
            }
            if ($window) $windows[] = $window;
        }
        foreach ($windows as &$window) $window['key'] = GamRevenueCorrectionService::hash($window);
        unset($window);
        $counts['windows'] = $counts['eligible_windows'] = count($windows);
        ksort($records); ksort($coverage); ksort($reasons);
        return compact('records', 'coverage', 'windows', 'counts', 'reasons');
    }
}
