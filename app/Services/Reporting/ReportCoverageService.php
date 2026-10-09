<?php

namespace App\Services\Reporting;

use App\Models\{DailyReport, Publisher, Site, SiteGamReportBinding, SiteGamVideoReportBinding};
use Carbon\CarbonImmutable;

/** Presence, not financial finality or proof of complete intraday delivery. */
final class ReportCoverageService
{
    public function forPeriod(string $from, string $to, string $currency, Publisher|Site|null $owner = null, ?array $siteIds = null): array
    {
        $result = ['main' => $this->emptyCoverage(), 'video' => $this->emptyCoverage()];
        if ($owner === null && (! auth()->user()?->isHorusAdministrator() || ! auth()->user()?->hasPermission('reporting.admin.view'))) {
            return $result;
        }

        foreach (['main' => SiteGamReportBinding::class, 'video' => SiteGamVideoReportBinding::class] as $channel => $model) {
            $query = $model::query()->whereNotNull('active_site_id')->with([
                'site', 'connection.source',
                // A publisher binding may use the shared Horus-owned account.
                // Read only its enabled flag, retaining soft-delete filtering.
                'gamConnection' => fn ($gam) => $gam->withoutGlobalScope('organization')->select('id', 'is_enabled'),
            ]);
            if ($channel === 'video') $query->whereNull('cancelled_at');
            if ($siteIds !== null) $query->whereIn('site_id', $siteIds);
            if ($owner !== null) {
                $query->where('organization_id', $owner->organization_id);
                if ($owner instanceof Site) $query->where('site_id', $owner->id);
                else $query->whereHas('site', fn ($site) => $site->where('publisher_id', $owner->id)->where('organization_id', $owner->organization_id));
            }

            $expected = [];
            foreach ($query->get() as $binding) {
                $connection = $binding->connection;
                if (! $connection || ! $binding->site || $connection->currency !== $currency
                    || ! $connection->is_enabled || $connection->status->value === 'DISABLED' || ! $connection->source?->is_enabled
                    || ! $binding->gamConnection?->is_enabled
                    || $connection->connection_type !== $binding->connectionType()
                    || $binding->active_site_id !== $binding->site_id
                    || $connection->organization_id !== $binding->organization_id
                    || $binding->site->organization_id !== $binding->organization_id) continue;
                try {
                    if (! is_string($connection->timezone) || trim($connection->timezone) === '') continue;
                    $today = CarbonImmutable::now(new \DateTimeZone($connection->timezone))->toDateString();
                } catch (\Exception) { continue; }

                // Check the latest selected source day. This covers UTC Today
                // after an eastern source midnight without hiding yesterday's
                // still-estimated report, and explains a western day not begun.
                $date = $from > $today ? $from : min($to, $today);
                if ($date < $binding->starts_on->toDateString()
                    || ($binding->ends_on && $date > $binding->ends_on->toDateString())) continue;
                $expected[] = [
                    'binding' => $binding, 'date' => $date, 'not_started' => $date > $today,
                ];
            }

            $facts = collect();
            if ($expected !== []) {
                $facts = ReportDisplayQuery::constrain(DailyReport::query(), true)
                    ->where('currency', $currency)
                    ->whereIn('report_source_connection_id', collect($expected)->pluck('binding.report_source_connection_id')->all())
                    ->whereDate('report_date', '>=', collect($expected)->pluck('date')->min())
                    ->whereDate('report_date', '<=', collect($expected)->pluck('date')->max())
                    ->with('dimension')->get()->keyBy(fn ($row) => implode('|', [
                        $row->organization_id, $row->dimension?->organization_id,
                        $row->dimension?->site_id, $row->report_source_connection_id, $row->report_date->toDateString(),
                    ]));
            }

            foreach ($expected as $entry) {
                $binding = $entry['binding'];
                $key = implode('|', [$binding->organization_id, $binding->organization_id, $binding->site_id, $binding->report_source_connection_id, $entry['date']]);
                $result[$channel]['expected_count']++;
                if (! $entry['not_started'] && $facts->has($key)) {
                    $result[$channel]['reported_count']++;
                    continue;
                }
                $result[$channel]['missing_count']++;
                $result[$channel]['pending_sites'][] = [
                    'site_id' => $binding->site_id, 'label' => $binding->site->display_name,
                    'date' => $entry['date'], 'timezone' => $binding->connection->timezone,
                    'state' => $entry['not_started'] ? 'not_started' : 'awaiting_data',
                ];
            }
        }

        return $result;
    }

    private function emptyCoverage(): array
    {
        return ['expected_count' => 0, 'reported_count' => 0, 'missing_count' => 0, 'pending_sites' => []];
    }
}
