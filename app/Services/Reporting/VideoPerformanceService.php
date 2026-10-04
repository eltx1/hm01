<?php

namespace App\Services\Reporting;

use App\Enums\ReportFinality;
use App\Models\{Publisher, Site, SiteGamVideoReportBinding};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Separate reporting surface; publisher projections never contain internal allocations. */
final class VideoPerformanceService
{
    public static function isVideo(mixed $row): bool
    {
        $source = data_get($row, 'connection.source');
        $code = $source instanceof \Illuminate\Database\Eloquent\Model ? $source->getRawOriginal('code') : data_get($source, 'code');
        if ($code instanceof \BackedEnum) $code = $code->value;
        return data_get($row, 'connection.connection_type') === 'SITE_GAM_VIDEO_AD_UNIT' || $code === 'GAM_VIDEO_AD_UNIT';
    }

    public static function constrain(Builder $query, bool $video): Builder
    {
        $method = $video ? 'whereHas' : 'whereDoesntHave';
        return $query->{$method}('connection', fn ($connection) => $connection->where(function ($connection): void {
            $connection->where('connection_type', 'SITE_GAM_VIDEO_AD_UNIT')
                ->orWhereHas('source', fn ($source) => $source->where('code', 'GAM_VIDEO_AD_UNIT'));
        }));
    }

    /** Scope configuration reads to the same authorized owner as the report. */
    public function configuration(Publisher|Site|null $owner = null): array
    {
        $query = SiteGamVideoReportBinding::query()->whereNotNull('active_site_id')
            ->whereHas('connection', fn ($connection) => $connection->where('is_enabled', true))
            ->with('connection');
        if ($owner instanceof Site) {
            $query->where('organization_id', $owner->organization_id)->where('site_id', $owner->id);
        } elseif ($owner instanceof Publisher) {
            $query->where('organization_id', $owner->organization_id)
                ->whereHas('site', fn ($site) => $site->where('publisher_id', $owner->id)->where('organization_id', $owner->organization_id));
        } else {
            // Null means the staff-wide report; never infer this permission from a caller flag.
            if (! auth()->user()?->isHorusAdministrator() || ! auth()->user()?->hasPermission('reporting.admin.view')) {
                return ['configured' => false, 'timezones' => []];
            }
        }
        $bindings = $query->get();
        return ['configured' => $bindings->isNotEmpty(), 'timezones' => $bindings->pluck('connection.timezone')->filter()->unique()->sort()->values()->all()];
    }

    public function summary(Collection $rows, bool $publisher, string $currency, array $configuration = []): array
    {
        $rows = $rows->filter(fn ($row) => self::isVideo($row));
        app(SiteGamUnfilledProjection::class)->preload($rows);
        return [
            'available' => $rows->isNotEmpty(), 'currency' => $currency,
            'configured' => (bool) ($configuration['configured'] ?? false),
            'updated_at' => $rows->max('updated_at'),
            ...$this->totals($rows, $publisher),
            'timezones' => $rows->pluck('connection.timezone')->concat($configuration['timezones'] ?? [])->filter()->unique()->sort()->values()->all(),
            'days' => $rows->groupBy(fn ($row) => $row->report_date->toDateString())->sortKeys()
                ->map(fn ($group, $date) => ['date' => $date, ...$this->totals($group, $publisher)])->values(),
            'websites' => $rows->groupBy(fn ($row) => $row->dimension?->site_id ?? 'unassigned')
                ->map(fn ($group) => ['label' => $group->first()->dimension?->site?->display_name ?? 'Unassigned', ...$this->totals($group, $publisher)])->values(),
        ];
    }

    private function totals(Collection $rows, bool $publisher): array
    {
        $metrics = app(PerformanceMetrics::class)->summarize($rows, $publisher ? 'publisher_earnings_minor' : 'gross_revenue_minor');
        return [
            'impressions' => $metrics['impressions'], 'ecpm_minor' => $metrics['ecpm_minor'],
            'unfilled_impressions' => $metrics['unfilled_impressions'], 'unfilled_scope' => 'AD_UNIT_ALL_SITES_V1',
            'metric_basis_incomplete' => $metrics['metric_basis_incomplete'],
            'timezones' => $rows->pluck('connection.timezone')->filter()->unique()->sort()->values()->all(),
            'has_estimates' => $rows->contains('finality', ReportFinality::Estimated),
            'revenue_minor' => (int) $rows->sum($publisher ? 'publisher_earnings_minor' : 'gross_revenue_minor'),
            ...($publisher ? [] : [
                'gross_revenue_minor' => (int) $rows->sum('gross_revenue_minor'),
                'publisher_earnings_minor' => (int) $rows->sum('publisher_earnings_minor'),
                'horus_earnings_minor' => (int) $rows->sum('horus_earnings_minor'),
            ]),
        ];
    }
}
