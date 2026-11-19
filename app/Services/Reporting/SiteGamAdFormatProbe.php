<?php

namespace App\Services\Reporting;

use App\Models\DailyReport;
use App\Models\Site;
use App\Models\SiteGamReportBinding;
use App\Services\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Read-only live verification: does this Google Ad Manager network accept
 * Ad Exchange metrics broken down by inventory format for this website?
 *
 * Runs the exact production query for one completed day. If Google rejects
 * it, narrower variants identify which part is refused. It stores nothing:
 * no ledger facts, no format rows, no import or sync checkpoints.
 */
final class SiteGamAdFormatProbe
{
    /** @var \Closure(int): void */
    private \Closure $sleep;

    public function __construct(
        private readonly GamAdUnitReportClient $google,
        private readonly GamReportMoneyParser $money,
        private readonly SiteGamReportScope $scopes,
        private readonly SiteGamAdFormatSynchronizer $synchronizer,
        private readonly AuditRecorder $audit,
    ) {
        $this->sleep = static fn (int $seconds) => sleep($seconds);
    }

    public function withSleeper(\Closure $sleep): self
    {
        $clone = clone $this;
        $clone->sleep = $sleep;

        return $clone;
    }

    public function run(Site $site, ?string $date = null, int $waitSeconds = 45, ?\Illuminate\Contracts\Auth\Authenticatable $actor = null): array
    {
        $binding = SiteGamReportBinding::withoutGlobalScopes()->with(['connection' => fn ($q) => $q->withoutGlobalScopes(), 'gamConnection' => fn ($q) => $q->withoutGlobalScopes(), 'site' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('site_id', $site->id)->whereNotNull('active_site_id')->first();
        if (! $binding || ! $binding->connection || ! $binding->gamConnection || $binding->organization_id !== $site->organization_id) {
            return $this->finish($site, null, ['verdict' => 'NOT_CONNECTED', 'message' => 'This website has no GAM reporting ad unit connected.'], $actor);
        }
        $timezone = $binding->connection->timezone;
        $date ??= CarbonImmutable::now($timezone)->subDay()->toDateString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || $date >= CarbonImmutable::now($timezone)->toDateString()
            || $date < $binding->starts_on->toDateString()) {
            return $this->finish($site, $binding, ['verdict' => 'INVALID_DATE', 'message' => 'Choose a completed day inside the binding period.'], $actor);
        }
        $hostname = $this->scopes->hostname((string) $site->primary_domain);
        $currency = strtoupper((string) config('reporting.canonical_currency', 'USD'));
        try {
            $network = $this->google->call($binding->gamConnection, 'NetworkService', 'getCurrentNetwork');
        } catch (MainReportSyncLockException $error) {
            throw $error;
        } catch (\Throwable $error) {
            return $this->finish($site, $binding, ['verdict' => 'GOOGLE_UNAVAILABLE', 'message' => $this->safe($error)], $actor);
        }
        if ((string) ($network['networkCode'] ?? '') !== $binding->network_code) {
            return $this->finish($site, $binding, ['verdict' => 'NETWORK_IDENTITY_CHANGED', 'message' => 'The Google network does not match this binding.'], $actor);
        }

        $production = $this->synchronizer->query($binding, $date, $date, $currency);
        $core = ['dimensions' => SiteGamAdFormatSynchronizer::DIMENSIONS, 'columns' => array_keys(SiteGamReportMetrics::CORE_COLUMNS)] + $production;
        $variants = [
            'production' => $production,
            'core_adx_metrics' => $core,
            'without_site_dimension' => ['dimensions' => ['DATE', 'AD_UNIT_ID', ...SiteGamAdFormatSynchronizer::FORMAT_DIMENSIONS]] + $core,
            'format_name_and_adx_revenue_only' => ['dimensions' => ['DATE', 'AD_UNIT_ID', 'INVENTORY_FORMAT_NAME'],
                'columns' => ['AD_EXCHANGE_LINE_ITEM_LEVEL_REVENUE']] + $core,
        ];
        $attempts = [];
        foreach ($variants as $name => $query) {
            $attempt = $this->attempt($binding, $query, $waitSeconds);
            $attempts[$name] = array_diff_key($attempt, ['csv' => true, 'confirmed_currency' => true]);
            if ($attempt['status'] === 'COMPLETED' && $name === 'production') {
                return $this->finish($site, $binding, ['verdict' => 'SUPPORTED', 'date' => $date, 'hostname' => $hostname,
                    'attempts' => $attempts,
                    ...$this->evidence($site, $binding, $attempt, $hostname, $date, $currency, (string) $network['currencyCode'])], $actor);
            }
            if ($attempt['status'] === 'TIMEOUT' || $attempt['status'] === 'GOOGLE_UNAVAILABLE') {
                return $this->finish($site, $binding, ['verdict' => 'INCONCLUSIVE', 'date' => $date, 'attempts' => $attempts,
                    'message' => 'Google did not finish in time or was unavailable. Try again; nothing was stored.'], $actor);
            }
            if ($attempt['status'] === 'COMPLETED') {
                // A narrower variant works: report exactly which part Google refused.
                return $this->finish($site, $binding, ['verdict' => 'PARTIALLY_SUPPORTED', 'date' => $date, 'attempts' => $attempts,
                    'accepted_variant' => $name,
                    'message' => 'Google rejected the production query but accepted "'.$name.'".'], $actor);
            }
        }

        return $this->finish($site, $binding, ['verdict' => 'UNSUPPORTED', 'date' => $date, 'attempts' => $attempts,
            'message' => 'Google rejected Ad Exchange metrics by inventory format for this network.'], $actor);
    }

    private function attempt(SiteGamReportBinding $binding, array $query, int $waitSeconds): array
    {
        try {
            $response = $this->google->call($binding->gamConnection, 'ReportService', 'runReportJob', ['reportJob' => ['reportQuery' => $query]]);
        } catch (MainReportSyncLockException $error) {
            throw $error;
        } catch (\Throwable $error) {
            return ['status' => SiteGamAdFormatSynchronizer::unsupported($error) ? 'REJECTED' : 'GOOGLE_UNAVAILABLE',
                'error' => $this->safe($error), 'dimensions' => $query['dimensions'], 'columns' => $query['columns']];
        }
        $id = (string) ($response['id'] ?? '');
        if (! ctype_digit($id)) return ['status' => 'GOOGLE_UNAVAILABLE', 'error' => 'Invalid report job ID.', 'dimensions' => $query['dimensions'], 'columns' => $query['columns']];
        $deadline = microtime(true) + max(0, $waitSeconds);
        do {
            try {
                $status = (string) ($this->google->call($binding->gamConnection, 'ReportService', 'getReportJobStatus', ['reportJobId' => $id])['value'] ?? '');
            } catch (MainReportSyncLockException $error) {
                throw $error;
            } catch (\Throwable $error) {
                return ['status' => 'GOOGLE_UNAVAILABLE', 'error' => $this->safe($error), 'job_id' => $id, 'dimensions' => $query['dimensions'], 'columns' => $query['columns']];
            }
            if ($status === 'COMPLETED') {
                try {
                    $csv = $this->google->download($binding->gamConnection, $id);
                } catch (MainReportSyncLockException $error) {
                    throw $error;
                } catch (\Throwable $error) {
                    return ['status' => 'GOOGLE_UNAVAILABLE', 'error' => $this->safe($error), 'job_id' => $id, 'dimensions' => $query['dimensions'], 'columns' => $query['columns']];
                }

                return ['status' => 'COMPLETED', 'job_id' => $id, 'csv' => $csv, 'confirmed_currency' => $this->money->confirmedCurrency($response, (string) ($query['reportCurrency'] ?? 'USD')),
                    'dimensions' => $query['dimensions'], 'columns' => $query['columns']];
            }
            if ($status === 'FAILED') {
                return ['status' => 'REJECTED', 'error' => 'Google accepted the query but failed to build the report.', 'job_id' => $id, 'dimensions' => $query['dimensions'], 'columns' => $query['columns']];
            }
            if (microtime(true) >= $deadline) break;
            ($this->sleep)(3);
        } while (true);

        return ['status' => 'TIMEOUT', 'job_id' => $id, 'dimensions' => $query['dimensions'], 'columns' => $query['columns']];
    }

    /** Compare Google's per-format AdX revenue with the stored ledger total for the same site and day. */
    private function evidence(Site $site, SiteGamReportBinding $binding, array $attempt, string $hostname, string $date, string $currency, string $networkCurrency): array
    {
        try {
            $rows = $this->synchronizer->parse($attempt['csv'], $binding->ad_unit_id, $hostname, $date, $date, $currency, $networkCurrency, $attempt['confirmed_currency']);
        } catch (\Throwable $error) {
            return ['parse_error' => $this->safe($error), 'formats' => []];
        }
        $micros = array_sum(array_column($rows, 'revenue_micros'));
        $formatMinor = ($micros < 0 ? -1 : 1) * intdiv(abs($micros) + 5000, 10000);
        $ledger = DailyReport::withoutGlobalScopes()->where('organization_id', $site->organization_id)
            ->where('report_source_connection_id', $binding->report_source_connection_id)
            ->whereDate('report_date', $date)->where('currency', $currency)->get();

        return [
            'formats' => array_map(fn ($row) => [
                'format' => AdFormatCatalog::label($row['ad_format'], $row['google_format_name']),
                'google_name' => $row['google_format_name'], 'google_id' => $row['google_format_id'],
                'ad_requests' => $row['ad_requests'], 'impressions' => $row['impressions'], 'clicks' => $row['clicks'],
                'revenue_minor' => ($row['revenue_micros'] < 0 ? -1 : 1) * intdiv(abs($row['revenue_micros']) + 5000, 10000),
            ], $rows),
            'google_format_revenue_minor' => $formatMinor,
            'google_format_impressions' => array_sum(array_column($rows, 'impressions')),
            'ledger_available' => $ledger->isNotEmpty(),
            'ledger_gross_revenue_minor' => $ledger->isEmpty() ? null : (int) $ledger->sum('gross_revenue_minor'),
            'ledger_impressions' => $ledger->isEmpty() ? null : (int) $ledger->sum('impressions'),
            'difference_minor' => $ledger->isEmpty() ? null : $formatMinor - (int) $ledger->sum('gross_revenue_minor'),
        ];
    }

    private function finish(Site $site, ?SiteGamReportBinding $binding, array $result, $actor): array
    {
        $result['checked_at'] = now()->toIso8601String();
        $result['stored'] = false;
        $this->audit->record('reporting.site_gam.ad_format_probe', $site->organization_id, actor: $actor instanceof \Illuminate\Database\Eloquent\Model ? $actor : null,
            auditable: $binding ?? $site, metadata: ['verdict' => $result['verdict'], 'date' => $result['date'] ?? null,
                'attempts' => array_map(fn ($attempt) => $attempt['status'], $result['attempts'] ?? []), 'financial_rows_changed' => false]);

        return $result;
    }

    private function safe(\Throwable $error): string
    {
        // Never surface signed URLs, tokens or credentials from transport errors.
        $message = preg_replace('#https?://\S+#', '[url]', $error->getMessage()) ?? '';

        return mb_substr(trim(preg_replace('/(token|secret|key|signature)=\S+/i', '$1=[redacted]', $message) ?? ''), 0, 300);
    }
}
