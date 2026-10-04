<?php

namespace App\Services\Reporting;

use App\Models\SiteGamReportBinding;
use App\Models\SiteGamUnfilledReport;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** Read-only unit totals. These never become exact-hostname financial facts. */
final class SiteGamUnfilledProjection
{
    private const RELATION = 'siteGamUnfilledProjection';

    public function preload(Collection $rows): void
    {
        $basis = app(ReportMetricBasis::class);
        $this->resolve($rows->filter(fn ($row) => $basis->siteGam($row)));
    }

    public function total(Collection $rows): ?int
    {
        if ($rows->isEmpty()) return null;
        $basis = app(ReportMetricBasis::class);
        $siteRows = $rows->filter(fn ($row) => $basis->siteGam($row));
        $resolved = $this->resolve($siteRows);
        $units = [];
        $total = 0;
        foreach ($rows as $key => $row) {
            if ($basis->siteGam($row)) {
                $result = $resolved[$key];
                if ($result['value'] === null || $result['identity'] === null) return null;
                // A Google unit may have multiple facts, or credentials, but its
                // unit/day total must appear only once in a combined summary.
                if (array_key_exists($result['identity'], $units)) {
                    if ($units[$result['identity']] !== $result['value']) return null;
                    continue;
                }
                $units[$result['identity']] = $result['value'];
                $value = $result['value'];
            } else {
                $value = $this->counter(data_get($row, 'unfilled_impressions'));
                if ($value === null) return null;
            }
            if ($value > PHP_INT_MAX - $total) return null;
            $total += $value;
        }

        return $total;
    }

    private function resolve(Collection $rows): array
    {
        $results = [];
        $pending = [];
        foreach ($rows as $key => $row) {
            if ($row instanceof Model && $row->relationLoaded(self::RELATION)) {
                $results[$key] = $row->getRelation(self::RELATION);
                continue;
            }
            $pending[$key] = $this->identity($row);
        }
        if ($pending === []) return $results;
        $valid = collect($pending)->filter();
        $connections = $valid->pluck('connection')->unique()->all();
        $organizations = $valid->pluck('organization')->unique()->all();
        $days = $valid->pluck('day')->unique()->all();
        $bindings = $connections === [] ? collect() : SiteGamReportBinding::withoutGlobalScopes()
            ->whereIn('organization_id', $organizations)->whereIn('report_source_connection_id', $connections)
            ->with('connection')->get()->keyBy('report_source_connection_id');
        $reports = $connections === [] ? collect() : SiteGamUnfilledReport::withoutGlobalScopes()
            ->whereIn('organization_id', $organizations)->whereIn('report_source_connection_id', $connections)
            ->whereIn('report_date', $days)->get()
            ->keyBy(fn ($report) => $report->report_source_connection_id.'|'.$report->report_date->toDateString());

        foreach ($pending as $key => $fact) {
            $result = ['identity' => null, 'value' => null];
            $binding = $fact ? $bindings->get($fact['connection']) : null;
            if ($binding && $this->matchesBinding($fact, $binding)) {
                $result['identity'] = implode('|', [$binding->network_code, $binding->ad_unit_id, $binding->connection->timezone, $fact['day']]);
                $report = $reports->get($fact['connection'].'|'.$fact['day']);
                if ($report) {
                    if ($report->organization_id === $fact['organization']
                        && $report->site_gam_report_binding_id === $binding->id
                        && $report->gam_connection_id === $binding->gam_connection_id
                        && $report->network_code === $binding->network_code
                        && $report->ad_unit_id === $binding->ad_unit_id
                        && $report->timezone === $binding->connection->timezone) {
                        $result['value'] = $this->counter($report->unfilled_impressions);
                    }
                } elseif ($fact['legacy']) {
                    // The old verified SOAP unit report already stored this
                    // original metric. Absence of AdX attribution alone is not
                    // sufficient: binding, unit, owner and date must all match.
                    $result['value'] = $this->counter(data_get($rows[$key], 'metric_legacy_unfilled_impressions',
                        data_get($rows[$key], 'unfilled_impressions')));
                }
            }
            $results[$key] = $result;
            if ($rows[$key] instanceof Model) $rows[$key]->setRelation(self::RELATION, $result);
        }

        return $results;
    }

    private function identity(mixed $row): ?array
    {
        $date = data_get($row, 'report_date');
        $day = $date instanceof CarbonInterface ? $date->toDateString() : $date;
        $fact = [
            'connection' => data_get($row, 'report_source_connection_id'),
            'organization' => data_get($row, 'organization_id'),
            'dimension_organization' => data_get($row, 'metric_unit_organization_id', data_get($row, 'dimension.organization_id')),
            'site' => data_get($row, 'metric_unit_site_id', data_get($row, 'dimension.site_id')),
            'gam' => data_get($row, 'metric_unit_gam_connection_id', data_get($row, 'dimension.gam_connection_id')),
            'unit' => data_get($row, 'metric_unit_ad_unit_id', data_get($row, 'dimension.external_dimensions.gam_ad_unit_id')),
            'day' => $day,
        ];
        foreach ($fact as $value) {
            if (! is_string($value) || $value === '' || str_contains($value, "\0")) return null;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day)) return null;
        $legacy = data_get($row, 'metric_unit_legacy');
        $fact['legacy'] = $legacy !== null ? (int) $legacy === 1 :
            data_get($row, 'dimension.external_dimensions.gam_report_basis') === null
            && data_get($row, 'dimension.external_dimensions.gam_report_scope') === null
            && data_get($row, 'dimension.external_dimensions.gam_report_site') === null;

        return $fact;
    }

    private function matchesBinding(array $fact, SiteGamReportBinding $binding): bool
    {
        // Historical bindings remain readable after replacement or disablement.
        // A current hostname or current active binding is deliberately irrelevant.
        return $fact['dimension_organization'] === $fact['organization']
            && $binding->organization_id === $fact['organization'] && $binding->site_id === $fact['site']
            && $binding->gam_connection_id === $fact['gam'] && $binding->ad_unit_id === $fact['unit']
            && $binding->starts_on->toDateString() <= $fact['day']
            && (! $binding->ends_on || $binding->ends_on->toDateString() >= $fact['day'])
            && $binding->connection?->organization_id === $fact['organization']
            && $binding->connection?->connection_type === 'SITE_GAM_AD_UNIT'
            && $binding->connection?->connection_id === $binding->id
            && is_string($binding->connection?->timezone) && $binding->connection->timezone !== '';
    }

    private function counter(mixed $value): ?int
    {
        return (is_int($value) || is_string($value)) && preg_match('/^\d{1,15}$/D', (string) $value)
            ? (int) $value : null;
    }
}
