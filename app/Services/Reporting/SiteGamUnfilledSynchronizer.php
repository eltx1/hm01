<?php

namespace App\Services\Reporting;

use App\Models\ReportSourceConnection;
use App\Models\SiteGamReportBinding;
use App\Models\SiteGamUnfilledReport;
use App\Services\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Independent, nonfinancial original Google counter. Never a hostname total. */
final class SiteGamUnfilledSynchronizer
{
    public const COLUMN = 'TOTAL_INVENTORY_LEVEL_UNFILLED_IMPRESSIONS';
    public const SCOPE = 'AD_UNIT_ALL_SITES_V1';

    public function __construct(private readonly GamAdUnitReportClient $google, private readonly AuditRecorder $audit) {}

    /** At most one bounded report per pass; pending work resumes on the existing scheduler. */
    public function sync(SiteGamReportBinding $binding): array
    {
        return Cache::lock('site-gam-report:'.$binding->report_source_connection_id, 300)->block(3, function () use ($binding): array {
            $binding->refresh()->load('connection.source', 'gamConnection', 'site');
            $this->assertBinding($binding);
            $connection = $binding->connection;
            if (! $connection->is_enabled || ! $connection->source?->is_enabled || $connection->status->value === 'DISABLED'
                || ! $binding->gamConnection->is_enabled) return ['status' => 'DISABLED', 'stored_days' => 0];
            $now = CarbonImmutable::now($connection->timezone);
            $last = $binding->ends_on ? $now->min($binding->ends_on) : $now;
            if ($binding->starts_on->toDateString() > $last->toDateString()) return ['status' => 'NO_DATES', 'stored_days' => 0];
            $configuration = $connection->configuration ?? [];
            $state = $configuration['unit_unfilled'] ?? [];
            $identity = $this->identity($binding);
            if (($state['identity'] ?? null) !== $identity) $state = ['identity' => $identity];
            if (isset($state['pending']) && CarbonImmutable::parse($state['pending']['requested_at'])->lt(now()->subHours(6))) {
                unset($state['pending']);
                $this->checkpoint($binding, $identity, $state);
            }
            $ranges = [];
            // Resume the exact prior job even when midnight changed the current window.
            if (isset($state['pending'])) $ranges[] = [$state['pending']['from'], $state['pending']['to']];
            else {
                $cursor = (string) ($state['history_next'] ?? $binding->starts_on->toDateString());
                $end = $last->subDay()->toDateString();
                if ($cursor <= $end) $ranges[] = [$cursor, min(CarbonImmutable::parse($cursor)->addDays(30)->toDateString(), $end)];
                elseif (empty($state['next_refresh_at']) || CarbonImmutable::parse($state['next_refresh_at'])->lte(now())) {
                    // Recheck recent source revisions and today's running count, without reopening finance.
                    $ranges[] = [max($binding->starts_on->toDateString(), $last->subDays(6)->toDateString()), $last->toDateString()];
                }
            }
            if ($ranges === []) return ['status' => 'CURRENT', 'stored_days' => 0];
            [$from, $to] = $ranges[0];
            if ($from < $binding->starts_on->toDateString() || $to > $last->toDateString() || $from > $to
                || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 30) throw new RuntimeException('Invalid unit Unfilled report dates.');
            try {
                if (! isset($state['pending'])) {
                    $network = $this->google->call($binding->gamConnection, 'NetworkService', 'getCurrentNetwork');
                    if ((string) ($network['networkCode'] ?? '') !== $binding->network_code || ($network['timeZone'] ?? '') !== $connection->timezone) {
                        throw new RuntimeException('The Google network or timezone changed for unit Unfilled reporting.');
                    }
                    $date = static fn (string $day): array => ['year' => (int) substr($day, 0, 4), 'month' => (int) substr($day, 5, 2), 'day' => (int) substr($day, 8, 2)];
                    $query = ['dimensions' => ['DATE', 'AD_UNIT_ID'], 'columns' => [self::COLUMN], 'adUnitView' => 'FLAT',
                        'dateRangeType' => 'CUSTOM_DATE', 'startDate' => $date($from), 'endDate' => $date($to), 'timeZoneType' => 'PUBLISHER',
                        'statement' => ['query' => 'WHERE AD_UNIT_ID = :unit', 'values' => [
                            ['key' => 'unit', 'value' => ['__type' => 'NumberValue', 'value' => $binding->ad_unit_id]],
                        ]]];
                    // Required original metric: never remove it in an optional-column fallback.
                    $result = $this->google->call($binding->gamConnection, 'ReportService', 'runReportJob', ['reportJob' => ['reportQuery' => $query]]);
                    $id = (string) ($result['id'] ?? '');
                    if (! ctype_digit($id) || strlen($id) > 32) throw new RuntimeException('Invalid unit Unfilled Google job.');
                    $state['pending'] = ['id' => $id, 'from' => $from, 'to' => $to, 'requested_at' => now()->toIso8601String()];
                    $this->checkpoint($binding, $identity, $state);
                }
                $job = $state['pending'];
                $status = $this->google->call($binding->gamConnection, 'ReportService', 'getReportJobStatus', ['reportJobId' => $job['id']]);
                if (($status['value'] ?? '') !== 'COMPLETED') {
                    if (($status['value'] ?? '') === 'FAILED') {
                        unset($state['pending']);
                        throw new RuntimeException('Google unit Unfilled preparation failed or expired.');
                    }
                    return ['status' => 'PENDING', 'stored_days' => 0];
                }
                $csv = $this->google->download($binding->gamConnection, $job['id']);
                try { $days = self::parse($csv, $binding->ad_unit_id, $from, $to); }
                catch (\Throwable $error) { unset($state['pending']); throw $error; }
                $state = DB::transaction(function () use ($binding, $identity, $state, $days, $job, $to, $last): array {
                    $locked = SiteGamReportBinding::withoutGlobalScopes()->lockForUpdate()->findOrFail($binding->id);
                    $locked->load('connection', 'site', 'gamConnection');
                    $this->assertBinding($locked);
                    if ($this->identity($locked) !== $identity) throw new RuntimeException('Unit Unfilled binding changed during collection.');
                    foreach ($days as $day => $value) {
                        SiteGamUnfilledReport::withoutGlobalScopes()->updateOrCreate([
                            'report_source_connection_id' => $locked->report_source_connection_id, 'report_date' => $day,
                        ], ['organization_id' => $locked->organization_id, 'site_gam_report_binding_id' => $locked->id,
                            'gam_connection_id' => $locked->gam_connection_id, 'network_code' => $locked->network_code,
                            'ad_unit_id' => $locked->ad_unit_id, 'timezone' => $locked->connection->timezone,
                            'unfilled_impressions' => $value, 'google_report_job_id' => $job['id'], 'reported_at' => now()]);
                    }
                    // A missing CSV date is unknown, never a synthesized zero. Existing verified observations survive.
                    $state['history_next'] = max((string) ($state['history_next'] ?? $locked->starts_on->toDateString()), CarbonImmutable::parse($to)->addDay()->toDateString());
                    $state['next_refresh_at'] = ($to === $last->toDateString() ? now()->addHour() : now())->toIso8601String();
                    unset($state['pending'], $state['error']);
                    $this->checkpoint($locked, $identity, $state);
                    $this->audit->record('reporting.site_gam.unit_unfilled_synced', $locked->organization_id, auditable: $locked,
                        metadata: ['scope' => self::SCOPE, 'from' => $job['from'], 'to' => $job['to'], 'stored_days' => count($days), 'financial_rows_changed' => false]);
                    return $state;
                });
                return ['status' => 'COMPLETED', 'stored_days' => count($days)];
            } catch (\Throwable $error) {
                // Transport/status/download/storage failures resume the same Google job.
                // Only terminal, expired or malformed reports discard their checkpoint.
                $state['error'] = 'SOURCE_UNAVAILABLE';
                $this->checkpoint($binding, $identity, $state);
                // Do not change source financial health or erase verified metrics.
                return ['status' => 'SOURCE_UNAVAILABLE', 'stored_days' => 0];
            }
        });
    }

    public static function parse(string $csv, string $unit, string $from, string $to): array
    {
        $stream = fopen('php://temp', 'w+'); fwrite($stream, $csv); rewind($stream);
        try {
            $headers = fgetcsv($stream, escape: '');
            if (! is_array($headers)) throw new RuntimeException('Missing unit Unfilled CSV header.');
            $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
            $required = ['Dimension.DATE', 'Dimension.AD_UNIT_ID', 'Column.'.self::COLUMN];
            if (array_diff($required, $headers) || array_diff($headers, [...$required, 'Dimension.AD_UNIT_NAME']) || count(array_unique($headers)) !== count($headers)) {
                throw new RuntimeException('Unexpected unit Unfilled CSV definition.');
            }
            $days = [];
            while (($values = fgetcsv($stream, escape: '')) !== false) {
                if ($values === [null]) continue;
                if (count($values) !== count($headers)) throw new RuntimeException('Incomplete unit Unfilled CSV row.');
                $row = array_combine($headers, $values); $day = $row['Dimension.DATE']; $value = $row['Column.'.self::COLUMN];
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) || ! checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4))
                    || $day < $from || $day > $to || $row['Dimension.AD_UNIT_ID'] !== $unit || isset($days[$day])
                    || ! preg_match('/^\d+$/D', $value) || strlen(ltrim($value, '0')) > 15) throw new RuntimeException('Invalid unit Unfilled CSV row.');
                $days[$day] = (int) $value;
            }
            return $days;
        } finally { fclose($stream); }
    }

    private function assertBinding(SiteGamReportBinding $binding): void
    {
        if (! $binding->connection || ! $binding->site || ! $binding->gamConnection
            || $binding->connection->connection_type !== 'SITE_GAM_AD_UNIT' || $binding->connection->connection_id !== $binding->id
            || $binding->organization_id !== $binding->connection->organization_id || $binding->site->organization_id !== $binding->organization_id
            || (string) $binding->gamConnection->network_code !== $binding->network_code) throw new RuntimeException('Invalid unit Unfilled binding.');
    }

    private function identity(SiteGamReportBinding $binding): string
    {
        return hash('sha256', implode('|', [self::SCOPE, $binding->id, $binding->organization_id, $binding->site_id,
            $binding->gam_connection_id, $binding->network_code, $binding->ad_unit_id, $binding->connection->timezone,
            $binding->starts_on->toDateString(), $binding->ends_on?->toDateString()]));
    }

    private function checkpoint(SiteGamReportBinding $binding, string $identity, array $state): void
    {
        DB::transaction(function () use ($binding, $identity, $state): void {
            $connection = ReportSourceConnection::withoutGlobalScopes()->lockForUpdate()->findOrFail($binding->report_source_connection_id);
            $binding->load('connection', 'site', 'gamConnection');
            $this->assertBinding($binding);
            if ($this->identity($binding) !== $identity) throw new RuntimeException('Unit Unfilled binding changed.');
            $configuration = $connection->configuration ?? [];
            $configuration['unit_unfilled'] = $state;
            $connection->update(['configuration' => $configuration]);
        });
    }
}
