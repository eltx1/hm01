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
        $query = SiteGamVideoReportBinding::query()->whereNull('cancelled_at')
            ->with('connection');
        if ($owner instanceof Site) {
            $query->where('organization_id', $owner->organization_id)->where('site_id', $owner->id);
        } elseif ($owner instanceof Publisher) {
            $query->where('organization_id', $owner->organization_id)
                ->whereHas('site', fn ($site) => $site->where('publisher_id', $owner->id)->where('organization_id', $owner->organization_id));
        } else {
            // Null means the staff-wide report; never infer this permission from a caller flag.
            if (! auth()->user()?->isHorusAdministrator() || ! auth()->user()?->hasPermission('reporting.admin.view')) {
                return $this->configurationDefaults();
            }
        }
        $bindings = $query->get();
        $enabled = $bindings->filter(fn ($binding) => $binding->active_site_id !== null && $binding->connection?->is_enabled);
        $connections = $enabled->pluck('connection')->filter();
        $failed = $connections->filter(fn ($connection) => $this->sourceFailed($connection))->count();
        $pending = $connections->filter(fn ($connection) => $connection->last_successful_import_at === null
            && ! $this->sourceFailed($connection))->count();
        // Retired bindings can still finalize their last owned day. They are history,
        // not an enabled Video connection or a failure of the current connection.
        $visibleConnections = $enabled->isNotEmpty() ? $connections : $bindings->pluck('connection')->filter();

        return [
            'configured' => $enabled->isNotEmpty(),
            'has_configuration' => $bindings->isNotEmpty(),
            'configuration_state' => $enabled->isNotEmpty() ? 'enabled' : ($bindings->isNotEmpty() ? 'disabled' : 'unconfigured'),
            'starts_on' => $bindings->map(fn ($binding) => $binding->starts_on->toDateString())->min(),
            'ends_on' => $enabled->isEmpty() && $bindings->isNotEmpty() && $bindings->every(fn ($binding) => $binding->ends_on !== null)
                ? $bindings->map(fn ($binding) => $binding->ends_on->toDateString())->max() : null,
            'source_health' => $enabled->isNotEmpty() ? ($failed > 0 ? 'failed' : ($pending > 0 ? 'pending' : 'ready'))
                : ($bindings->isNotEmpty() ? 'disabled' : 'unconfigured'),
            'source_count' => $visibleConnections->count(),
            'pending_source_count' => $pending, 'failed_source_count' => $failed,
            'last_successful_import_at' => $visibleConnections->max('last_successful_import_at'),
            'timezones' => $visibleConnections->pluck('timezone')->filter()->unique()->sort()->values()->all(),
        ];
    }

    public function summary(Collection $rows, bool $publisher, string $currency, array $configuration = []): array
    {
        $rows = $rows->filter(fn ($row) => self::isVideo($row));
        app(SiteGamUnfilledProjection::class)->preload($rows);
        $days = $rows->groupBy(fn ($row) => $row->report_date->toDateString())->sortKeys()
            ->map(fn ($group, $date) => ['date' => $date, ...$this->totals($group, $publisher)])->values();
        $defaults = $this->configurationDefaults();
        if ($configuration['configured'] ?? false) {
            $defaults = array_replace($defaults, ['has_configuration' => true, 'configuration_state' => 'enabled', 'source_health' => 'pending']);
        }
        $configuration = array_replace($defaults, array_intersect_key($configuration, $defaults));
        return [
            'available' => $rows->isNotEmpty(), 'currency' => $currency,
            ...$configuration,
            'updated_at' => $rows->max('updated_at'),
            ...$this->totals($rows, $publisher),
            'timezones' => $rows->pluck('connection.timezone')->concat($configuration['timezones'] ?? [])->filter()->unique()->sort()->values()->all(),
            // Observed dates are not proof that every source imported every day.
            'reported_day_count' => $days->count(),
            'first_reported_on' => $days->first()['date'] ?? null,
            'last_reported_on' => $days->last()['date'] ?? null,
            'days' => $days,
            'websites' => $rows->groupBy(fn ($row) => $row->dimension?->site_id ?? 'unassigned')
                ->map(fn ($group) => [
                    'site_id' => $group->first()->dimension?->site_id,
                    'label' => $group->first()->dimension?->site?->display_name ?? 'Unassigned',
                    'domain' => $group->first()->dimension?->site?->primary_domain,
                    ...$this->totals($group, $publisher),
                ])->sortByDesc('revenue_minor')->values(),
        ];
    }

    private function configurationDefaults(): array
    {
        return [
            'configured' => false, 'has_configuration' => false, 'configuration_state' => 'unconfigured',
            'starts_on' => null, 'ends_on' => null, 'source_health' => 'unconfigured',
            'source_count' => 0, 'pending_source_count' => 0, 'failed_source_count' => 0,
            'last_successful_import_at' => null, 'timezones' => [],
        ];
    }

    private function sourceFailed(\App\Models\ReportSourceConnection $connection): bool
    {
        $failed = $connection->status->value === 'ERROR' || trim((string) $connection->last_error) !== '';
        if (! $failed) return false;
        // A later success supersedes an older error even if a legacy source did
        // not clear its error text. Never expose the raw source error to publishers.
        if ($connection->last_successful_import_at && $connection->last_attempted_at
            && $connection->last_successful_import_at->gt($connection->last_attempted_at)) {
            return false;
        }

        return $connection->status->value === 'ERROR' || $connection->last_successful_import_at === null
            || ($connection->last_attempted_at && $connection->last_attempted_at->gt($connection->last_successful_import_at));
    }

    private function totals(Collection $rows, bool $publisher): array
    {
        $metrics = app(PerformanceMetrics::class)->summarize($rows, $publisher ? 'publisher_earnings_minor' : 'gross_revenue_minor');
        return [
            'impressions' => $metrics['impressions'], 'ecpm_minor' => $metrics['ecpm_minor'],
            'unfilled_impressions' => $metrics['unfilled_impressions'], 'unfilled_scope' => 'AD_UNIT_ALL_SITES_V1',
            'has_site_ad_exchange' => true,
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
