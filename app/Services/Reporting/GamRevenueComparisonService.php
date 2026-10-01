<?php

namespace App\Services\Reporting;

use App\Models\DailyReport;
use App\Models\RevenueRuleVersion;
use App\Models\Site;
use App\Models\SiteGamReportBinding;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Historical evidence only: never calls scope ensure(), importers or finance writers. */
final class GamRevenueComparisonService
{
    public function __construct(
        private readonly GamAdUnitReportClient $google,
        private readonly GamReportMoneyParser $money,
        private readonly GamRevenueComparisonCsv $csv,
        private readonly SiteGamReportScope $scopes,
        private readonly RevenueCalculator $calculator,
    ) {}

    public function context(Site $site, string $from, string $to): array
    {
        $binding = $site->currentGamReportBinding()->with(['connection.source', 'gamConnection', 'site'])->first();
        if (! $binding) $this->invalid('This site needs an existing enabled GAM reporting binding.');
        $this->validateBinding($binding);
        $fromDate = CarbonImmutable::createFromFormat('!Y-m-d', $from);
        $toDate = CarbonImmutable::createFromFormat('!Y-m-d', $to);
        if (! $fromDate || ! $toDate || $fromDate->format('Y-m-d') !== $from || $toDate->format('Y-m-d') !== $to
            || $toDate->lt($fromDate) || $fromDate->diffInDays($toDate) > 30
            || $from < $binding->starts_on->toDateString() || ($binding->ends_on && $to > $binding->ends_on->toDateString())) {
            $this->invalid('Choose no more than 31 days entirely within this binding’s effective period.');
        }
        $network = $this->network($binding);
        if ($to >= CarbonImmutable::now($network['timeZone'])->toDateString()) $this->invalid('Use completed days in the Google network timezone.');
        return [
            'site_id' => $site->id, 'organization_id' => $site->organization_id, 'publisher_id' => $site->publisher_id,
            'hostname' => $this->scopes->hostname($site->primary_domain), 'binding_id' => $binding->id,
            'source_connection_id' => $binding->report_source_connection_id, 'gam_connection_id' => $binding->gam_connection_id,
            'report_source_id' => $binding->connection->report_source_id,
            'network_code' => $binding->network_code, 'ad_unit_id' => $binding->ad_unit_id,
            'network_currency' => $network['currencyCode'], 'currency' => 'USD', 'timezone' => $network['timeZone'],
            'from' => $from, 'to' => $to, 'captured_at' => now()->toIso8601String(),
            'binding_fingerprint' => $this->fingerprint($binding),
        ];
    }

    public function assertContext(array $context): SiteGamReportBinding
    {
        $binding = SiteGamReportBinding::withoutGlobalScopes()->with(['site', 'connection.source', 'gamConnection'])->find($context['binding_id']);
        if (! $binding) $this->invalid('The binding changed. Start a new preview.');
        $this->validateBinding($binding);
        if (! hash_equals($context['binding_fingerprint'], $this->fingerprint($binding))) $this->invalid('The binding or source changed. Start a new preview.');
        return $binding;
    }

    public function assertEvidence(array $entry): SiteGamReportBinding
    {
        $binding = $this->assertContext($entry['context']);
        if (! hash_equals($entry['snapshot']['fingerprint'], $this->snapshot($entry['context'])['fingerprint'])) {
            $this->invalid('Stored facts, periods or original revenue rules changed. Start a new preview.');
        }
        if (! hash_equals($entry['query_hash'], $this->queryHash($entry['context']))) $this->invalid('The report query changed. Start a new preview.');
        return $binding;
    }

    public function query(array $context): array
    {
        $date = fn (string $day) => array_combine(['year', 'month', 'day'], array_map('intval', explode('-', $day)));
        return [
            'dimensions' => ['DATE', 'AD_UNIT_ID', 'SITE_NAME'], 'columns' => GamRevenueComparisonCsv::COLUMNS,
            'adUnitView' => 'FLAT', 'dateRangeType' => 'CUSTOM_DATE',
            'startDate' => $date($context['from']), 'endDate' => $date($context['to']),
            'timeZoneType' => 'PUBLISHER', 'reportCurrency' => 'USD',
            'statement' => ['query' => 'WHERE AD_UNIT_ID = :unit', 'values' => [
                ['key' => 'unit', 'value' => ['__type' => 'NumberValue', 'value' => $context['ad_unit_id']]],
            ]],
        ];
    }

    public function queryHash(array $context): string { return $this->hash($this->query($context)); }

    public function start(array $entry): array
    {
        $binding = $this->assertEvidence($entry);
        $this->network($binding, $entry['context']['network_currency']);
        // Deliberately no optional-column fallback and no saved report definition.
        $response = $this->google->call($binding->gamConnection, 'ReportService', 'runReportJob', ['reportJob' => ['reportQuery' => $this->query($entry['context'])]]);
        $id = (string) ($response['id'] ?? '');
        if (! ctype_digit($id)) throw new RuntimeException('INVALID_REPORT_JOB');
        return ['id' => $id, 'status' => 'PENDING', 'confirmed_currency' => $this->money->confirmedCurrency($response, 'USD'),
            'next_poll_at' => now()->addSeconds(10)->timestamp, 'polls' => 0];
    }

    public function poll(array $entry): array
    {
        $binding = $this->assertEvidence($entry);
        $job = $entry['job'];
        if ($job['status'] !== 'PENDING' || now()->timestamp < $job['next_poll_at']) return $job;
        if (++$job['polls'] > 60) throw new RuntimeException('POLL_LIMIT_REACHED');
        $this->network($binding, $entry['context']['network_currency']);
        $response = $this->google->call($binding->gamConnection, 'ReportService', 'getReportJobStatus', ['reportJobId' => $job['id']]);
        $status = $response['value'] ?? '';
        if ($status === 'FAILED') throw new RuntimeException('GOOGLE_REPORT_FAILED');
        if (! in_array($status, ['IN_PROGRESS', 'COMPLETED'], true)) throw new RuntimeException('INVALID_REPORT_STATUS');
        $job['next_poll_at'] = now()->addSeconds(15)->timestamp;
        if ($status !== 'COMPLETED') return $job;
        $fresh = $this->csv->analyze($this->google->download($binding->gamConnection, $job['id']), $entry['context'], $job['confirmed_currency']);
        $this->assertEvidence($entry);
        $job['result'] = $this->compare($entry['context'], $entry['snapshot'], $fresh);
        $job['status'] = 'COMPLETED';
        $job['completed_at'] = now()->toIso8601String();
        return $job;
    }

    public function snapshot(array $context): array
    {
        // Include all site sources and all facts for this source. That exposes
        // mixed identities rather than silently selecting a convenient subset.
        $rows = DailyReport::withoutGlobalScopes()->where('organization_id', $context['organization_id'])
            ->where(fn ($q) => $q->where('report_source_connection_id', $context['source_connection_id'])
                ->orWhereHas('dimension', fn ($q) => $q->where('site_id', $context['site_id'])))
            ->whereDate('report_date', '>=', $context['from'])->whereDate('report_date', '<=', $context['to'])
            ->with(['dimension', 'revenueRuleVersion.rule' => fn ($q) => $q->withoutGlobalScopes(), 'period'])
            ->orderBy('id')->limit(129)->get();
        if ($rows->count() > 128) $this->invalid('This window has too many stored dimensions. Choose a smaller window for review.');
        $facts = $rows->map(fn ($row) => [
            'fact' => $row->getAttributes(), 'dimension' => $row->dimension?->getAttributes(),
            'rule_version' => $row->revenueRuleVersion?->getAttributes(),
            'rule' => $row->revenueRuleVersion?->rule?->getAttributes(), 'period' => $row->period?->getAttributes(),
        ])->all();
        return ['fingerprint' => $this->hash($facts), 'facts' => $facts, 'adjustments' => 'Separate approved adjustments are excluded and remain unchanged.'];
    }

    public function compare(array $context, array $snapshot, array $fresh): array
    {
        $days = [];
        for ($day = CarbonImmutable::parse($context['from']); $day->toDateString() <= $context['to']; $day = $day->addDay()) {
            $date = $day->toDateString();
            $facts = array_values(array_filter($snapshot['facts'], fn ($row) => substr($row['fact']['report_date'], 0, 10) === $date));
            $flags = [];
            $stored = array_fill_keys(['gross_revenue_minor', 'impressions', 'publisher_earnings_minor', 'horus_earnings_minor', 'mcm_partner_earnings_minor'], 0);
            if (count($facts) !== 1) $flags[] = count($facts) ? 'MULTIPLE_STORED_FACTS_NO_ALLOCATION' : 'NO_ORIGINAL_RULE';
            foreach ($facts as $row) $flags = array_merge($flags, $this->factFlags($row, $context, $date));
            $storedAmountsVerified = ! in_array('UNVERIFIED_STORED_AMOUNT', $flags, true);
            if ($storedAmountsVerified) {
                foreach ($facts as $row) foreach ($stored as $field => $_) $stored[$field] += (int) ($row['fact'][$field] ?? 0);
            }
            $observed = isset($fresh['days'][$date]);
            if (! $observed) $flags[] = 'NO_EXACT_SITE_ROW';
            $new = $fresh['days'][$date] ?? null;
            $projection = null;
            if (! $flags) {
                $row = $facts[0];
                $rule = new RevenueRuleVersion;
                $rule->setRawAttributes($row['rule_version']);
                $projection = $this->calculator->calculate($new['gross_revenue_minor'],
                    (int) $row['fact']['demand_partner_deductions_minor'], (int) $row['fact']['invalid_traffic_adjustments_minor'],
                    (int) $row['fact']['other_adjustments_minor'], $rule);
            }
            $mixedCurrency = in_array('MIXED_CURRENCY', $flags, true);
            $comparable = $observed && $facts && ! array_intersect($flags, [
                'MIXED_CURRENCY', 'MIXED_STORED_IDENTITY', 'UNVERIFIED_STORED_UNIT', 'MIXED_STORED_HOST',
                'UNSUPPORTED_STORED_DIMENSIONS', 'UNVERIFIED_STORED_AMOUNT', 'MULTIPLE_STORED_FACTS_NO_ALLOCATION',
            ]);
            $days[] = [
                'date' => $date, 'stored' => $mixedCurrency || ! $facts || ! $storedAmountsVerified ? null : $stored, 'fresh' => $new,
                'gross_delta_minor' => $comparable ? $new['gross_revenue_minor'] - $stored['gross_revenue_minor'] : null,
                'impressions_delta' => $comparable ? $new['impressions'] - $stored['impressions'] : null,
                'projected' => $projection, 'flags' => array_values(array_unique($flags)),
                'original_rule_version_id' => count($facts) === 1 ? ($facts[0]['fact']['revenue_rule_version_id'] ?? null) : null,
            ];
        }
        return ['days' => $days, 'exact_site_observed' => $fresh['exact_site_observed'], 'excluded_site_rows' => $fresh['excluded_site_rows'],
            'replacement_approved' => false, 'notice' => 'Comparison evidence only. No replacement or settlement safety is established. Separate approved adjustments are excluded and unchanged.'];
    }

    private function factFlags(array $row, array $context, string $date): array
    {
        $fact = $row['fact']; $dimension = $row['dimension'] ?? []; $version = $row['rule_version'] ?? []; $rule = $row['rule'] ?? [];
        $flags = [];
        if (($fact['organization_id'] ?? null) !== $context['organization_id'] || $fact['report_source_connection_id'] !== $context['source_connection_id'] || ($dimension['site_id'] ?? null) !== $context['site_id']
            || ($dimension['organization_id'] ?? null) !== $context['organization_id'] || ($dimension['publisher_id'] ?? null) !== $context['publisher_id']
            || ($dimension['gam_connection_id'] ?? null) !== $context['gam_connection_id']) $flags[] = 'MIXED_STORED_IDENTITY';
        if ($fact['currency'] !== 'USD') $flags[] = 'MIXED_CURRENCY';
        if ($fact['finality'] !== 'FINALIZED') $flags[] = 'NON_FINALIZED_FACT';
        foreach (['placement_id', 'demand_network_id', 'bidder_id', 'advertiser_id', 'campaign_id', 'country_code', 'device', 'browser', 'operating_system', 'ad_size'] as $key) {
            if (! empty($dimension[$key])) $flags[] = 'UNSUPPORTED_STORED_DIMENSIONS';
        }
        $external = json_decode($dimension['external_dimensions'] ?? '{}', true) ?? [];
        if (($external['gam_ad_unit_id'] ?? null) !== $context['ad_unit_id']) $flags[] = 'UNVERIFIED_STORED_UNIT';
        if (isset($external['gam_report_site']) && $external['gam_report_site'] !== $context['hostname']) $flags[] = 'MIXED_STORED_HOST';
        if (array_diff(array_keys($external), ['gam_ad_unit_id', 'gam_report_site', 'gam_report_scope'])) $flags[] = 'UNSUPPORTED_STORED_DIMENSIONS';
        $scopeMatches = match ($rule['scope_type'] ?? '') {
            'GLOBAL' => empty($rule['scope_id']),
            'PUBLISHER' => ($rule['scope_id'] ?? null) === $context['publisher_id'],
            'WEBSITE' => ($rule['scope_id'] ?? null) === $context['site_id'],
            'DEMAND_SOURCE' => in_array($rule['scope_id'] ?? null, [$context['report_source_id'], 'GAM_AD_UNIT'], true),
            'PUBLISHER_DEMAND_SOURCE' => in_array($rule['scope_id'] ?? null, [
                $context['publisher_id'].'|'.$context['report_source_id'], $context['publisher_id'].'|GAM_AD_UNIT',
            ], true),
            default => false,
        };
        if (! $version || ! $rule || ! $scopeMatches
            || ($version['id'] ?? null) !== ($fact['revenue_rule_version_id'] ?? null)
            || ($version['revenue_rule_id'] ?? null) !== ($rule['id'] ?? null)
            || ! in_array($version['currency'] ?? null, [null, 'USD'], true)
            || empty($rule['effective_from']) || substr($rule['effective_from'], 0, 10) > $date
            || (! empty($rule['effective_to']) && substr($rule['effective_to'], 0, 10) < $date)
            || empty($version['effective_from']) || substr($version['effective_from'], 0, 10) > $date
            || (! empty($version['effective_to']) && substr($version['effective_to'], 0, 10) < $date)
            || collect(['publisher_share_bp', 'horus_share_bp', 'mcm_partner_share_bp'])->contains(
                fn ($key) => ! isset($version[$key]) || ! preg_match('/^\d{1,5}$/D', (string) $version[$key]) || (int) $version[$key] > 10000)
            || (int) ($version['publisher_share_bp'] ?? 0) + (int) ($version['horus_share_bp'] ?? 0) + (int) ($version['mcm_partner_share_bp'] ?? 0) !== 10000) {
            $flags[] = 'UNVERIFIED_ORIGINAL_RULE';
        }
        $period = $row['period'] ?? [];
        if (($period['status'] ?? null) !== 'OPEN') $flags[] = 'PERIOD_NOT_OPEN';
        if (($period['id'] ?? null) !== ($fact['financial_period_id'] ?? null)
            || ($period['currency'] ?? null) !== 'USD'
            || ! in_array($period['organization_id'] ?? null, [null, $context['organization_id']], true)
            || empty($period['starts_on']) || substr($period['starts_on'], 0, 10) > $date
            || empty($period['ends_on']) || substr($period['ends_on'], 0, 10) < $date) $flags[] = 'UNVERIFIED_PERIOD';
        foreach (['gross_revenue_minor', 'demand_partner_deductions_minor', 'invalid_traffic_adjustments_minor',
            'other_adjustments_minor', 'net_revenue_minor', 'publisher_earnings_minor', 'horus_earnings_minor', 'mcm_partner_earnings_minor'] as $key) {
            if (! isset($fact[$key]) || ! preg_match('/^-?\d{1,13}$/D', (string) $fact[$key])) $flags[] = 'UNVERIFIED_STORED_AMOUNT';
        }
        if (! isset($fact['impressions']) || ! preg_match('/^\d{1,15}$/D', (string) $fact['impressions'])) $flags[] = 'UNVERIFIED_STORED_AMOUNT';
        if (! $flags) {
            $original = new RevenueRuleVersion;
            $original->setRawAttributes($version);
            $recomputed = $this->calculator->calculate((int) $fact['gross_revenue_minor'],
                (int) $fact['demand_partner_deductions_minor'], (int) $fact['invalid_traffic_adjustments_minor'],
                (int) $fact['other_adjustments_minor'], $original);
            foreach ($recomputed as $key => $value) {
                if ((int) $fact[$key] !== $value) $flags[] = 'ORIGINAL_ALLOCATION_MISMATCH';
            }
        }
        return $flags;
    }

    private function validateBinding(SiteGamReportBinding $binding): void
    {
        if (! $binding->site || ! $binding->active_site_id || ! $binding->connection?->is_enabled || ! $binding->gamConnection?->is_enabled
            || $binding->connection->status->value !== 'ACTIVE' || ! $binding->connection->source?->is_enabled
            || $binding->connection->source->code->value !== 'GAM_AD_UNIT' || $binding->connection->currency !== 'USD'
            || $binding->connection->connection_type !== 'SITE_GAM_AD_UNIT' || $binding->connection->connection_id !== $binding->id
            || $binding->site_id !== $binding->active_site_id || $binding->site->organization_id !== $binding->organization_id
            || $binding->connection->organization_id !== $binding->organization_id
            || $binding->network_code !== (string) $binding->gamConnection->network_code || ! ctype_digit($binding->ad_unit_id)
            || ! in_array($binding->connection->timezone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)) {
            $this->invalid('The enabled USD reporting source or binding identity is inconsistent.');
        }
    }

    private function network(SiteGamReportBinding $binding, ?string $expectedCurrency = null): array
    {
        $network = $this->google->call($binding->gamConnection, 'NetworkService', 'getCurrentNetwork');
        if ((string) ($network['networkCode'] ?? '') !== $binding->network_code || ($network['timeZone'] ?? '') !== $binding->connection->timezone
            || ! preg_match('/^[A-Z]{3}$/D', (string) ($network['currencyCode'] ?? ''))
            || ($expectedCurrency !== null && $network['currencyCode'] !== $expectedCurrency)) throw new RuntimeException('NETWORK_IDENTITY_CHANGED');
        return $network;
    }

    private function fingerprint(SiteGamReportBinding $binding): string
    {
        // Scheduler/importer checkpoints intentionally excluded: this reader
        // neither resumes nor modifies them. Attribution and source identity stay bound.
        return $this->hash([
            $binding->getAttributes(), $binding->site->only(['id', 'organization_id', 'publisher_id', 'primary_domain']),
            $binding->connection->only(['id', 'organization_id', 'report_source_id', 'connection_type', 'connection_id', 'account_identifier', 'currency', 'timezone', 'is_enabled', 'status']),
            data_get($binding->connection->configuration, 'site_report_scope'),
            $binding->connection->source->only(['id', 'code', 'is_enabled']),
            $binding->gamConnection->only(['id', 'organization_id', 'network_code', 'is_enabled', 'type']),
        ]);
    }

    public static function errorCode(\Throwable $error): string
    {
        foreach (['COLUMNS_NOT_SUPPORTED_FOR_REQUESTED_DIMENSIONS', 'INVALID_DIMENSION_FILTERS', 'TIME_ZONE_TYPE_NOT_SUPPORTED_FOR_REQUESTED_REPORT', 'CURRENCY_CODE_NOT_SUPPORTED_FOR_REQUESTED_REPORT', 'AD_UNIT_VIEW_NOT_SUPPORTED_FOR_REQUESTED_REPORT', 'DUPLICATE_CSV_ROW', 'OUT_OF_SCOPE_DATE', 'OUT_OF_SCOPE_UNIT', 'INVALID_CSV_HEADERS', 'GOOGLE_REPORT_FAILED', 'NETWORK_IDENTITY_CHANGED', 'POLL_LIMIT_REACHED'] as $code) {
            if (str_contains($error->getMessage(), $code)) return $code;
        }
        return 'PREVIEW_UNAVAILABLE_OR_UNVERIFIED';
    }

    private function hash(array $value): string { return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)); }
    private function invalid(string $message): never { throw ValidationException::withMessages(['comparison' => $message]); }
}
