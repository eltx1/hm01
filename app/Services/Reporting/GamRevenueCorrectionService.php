<?php

namespace App\Services\Reporting;

use App\Enums\ReportFinality;
use App\Enums\ReportGranularity;
use App\Enums\ReportImportStatus;
use App\Models\DailyReport;
use App\Models\FinancialPeriod;
use App\Models\GamConnection;
use App\Models\GamRevenueCorrection;
use App\Models\GamRevenueCorrectionReceipt;
use App\Models\HourlyReport;
use App\Models\ReportDimension;
use App\Models\ReportImportJob;
use App\Models\ReportSource;
use App\Models\ReportSourceConnection;
use App\Models\RevenueRule;
use App\Models\RevenueRuleVersion;
use App\Models\Site;
use App\Models\SiteGamReportBinding;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A bounded, explicitly reviewed replacement; never a normal-import bypass. */
final class GamRevenueCorrectionService
{
    public function __construct(
        private readonly GamRevenueComparisonService $comparisons,
        private readonly GamHistoricalCorrectionReport $reports,
        private readonly RevenueCalculator $calculator,
        private readonly ReportDimensionResolver $dimensions,
        private readonly FinancialSettlementEligibilityService $settlement,
        private readonly ReconciliationService $reconciliation,
        private readonly AuditRecorder $audit,
        private readonly SiteGamReportScope $scopes,
    ) {}

    public function start(Site $site, string $from, string $to, User $actor): GamRevenueCorrection
    {
        $this->authorize($actor);
        if (substr($from, 0, 7) !== substr($to, 0, 7)) $this->invalid('Choose a completed range within one calendar month.');

        return Cache::lock('gam-correction-actor:'.$actor->id, 180)->block(5, function () use ($site, $from, $to, $actor) {
            $existing = GamRevenueCorrection::where('actor_id', $actor->id)->where('site_id', $site->id)
                ->whereDate('period_start', $from)->whereDate('period_end', $to)
                ->where('status', '!=', 'SUPERSEDED')
                ->where('expires_at', '>', now())->latest()->first();
            if ($existing) return $existing;
            if (GamRevenueCorrection::where('actor_id', $actor->id)->where('expires_at', '>', now())->whereNotIn('status', ['APPLIED', 'SUPERSEDED'])->count() >= 6) {
                $this->invalid('At most six correction candidates may be active. Existing candidates expire after one hour.');
            }
            $context = $this->comparisons->context($site, $from, $to);
            $this->assertHistoricalWindow($context);
            $snapshot = $this->capture($context);
            [$candidate, $created] = DB::transaction(function () use ($actor, $site, $from, $to, $context, $snapshot) {
                // The cache is only a fast guard. A current DB read under the
                // actor row lock also serializes submissions if its TTL expires.
                User::withoutGlobalScopes()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $active = GamRevenueCorrection::where('actor_id', $actor->id)->where('expires_at', '>', now())
                    ->where('status', '!=', 'SUPERSEDED')->orderBy('id')->lockForUpdate()->get();
                $same = $active->first(fn ($item) => $item->site_id === $site->id
                    && $item->period_start === $from && $item->period_end === $to);
                if ($same) return [$same, false];
                if ($active->where('status', '!=', 'APPLIED')->count() >= 6) $this->invalid('At most six correction candidates may be active.');
                return [GamRevenueCorrection::create([
                    'actor_id' => $actor->id, 'organization_id' => $context['organization_id'], 'site_id' => $site->id,
                    'source_connection_id' => $context['source_connection_id'], 'period_start' => $from, 'period_end' => $to,
                    'status' => 'STARTING', 'context' => $context, 'snapshot' => $snapshot,
                    'query_hash' => $this->reports->queryHash($context), 'job' => ['status' => 'STARTING'],
                    'expires_at' => now()->addHour(),
                ]), true];
            });
            if (! $created) return $candidate;
            // Commit the attempt before calling Google. An interrupted request
            // cannot silently create a second report on a double click.
            try {
                $flags = $this->financeFlags($snapshot);
                if ($flags) {
                    $proposal = $this->proposal($context, $snapshot, ['days' => [], 'exact_site_observed' => false, 'excluded_site_rows' => 0]);
                    $candidate = $this->transition($candidate->id, 'STARTING', null,
                        ['status' => 'BLOCKED', 'proposal' => $proposal, 'error_code' => 'FINANCIAL_STATE_BLOCKED']);
                } else {
                    $job = $this->reports->start($context);
                    $candidate = $this->transition($candidate->id, 'STARTING', null, ['job' => $job, 'status' => 'PENDING']);
                }
            } catch (\Throwable $error) {
                $candidate = $this->transition($candidate->id, 'STARTING', null,
                    ['status' => 'FAILED', 'error_code' => GamRevenueComparisonService::errorCode($error)]);
            }
            $this->audit->record('reporting.gam_correction.prepared', $context['organization_id'], $actor, $candidate,
                newValues: ['status' => $candidate->status, 'query_hash' => $candidate->query_hash],
                metadata: ['financial_rows_changed' => false]);
            return $candidate->refresh();
        });
    }

    /** Explicit new evidence; never silently retry an uncertain in-flight job. */
    public function replace(GamRevenueCorrection $candidate, User $actor): GamRevenueCorrection
    {
        $this->owned($candidate, $actor);
        $context = Cache::lock('gam-correction:'.$candidate->id, 180)->block(5,
            fn () => DB::transaction(function () use ($candidate, $actor) {
                $current = GamRevenueCorrection::lockForUpdate()->findOrFail($candidate->id);
                $this->owned($current, $actor);
                if (! in_array($current->status, ['READY', 'BLOCKED', 'FAILED', 'SUPERSEDED'], true)) {
                    $this->invalid('Only a completed, blocked or failed review can be replaced. In-flight and applied candidates are preserved.');
                }
                if ($current->status !== 'SUPERSEDED') {
                    $previous = $current->status;
                    $current->update(['status' => 'SUPERSEDED']);
                    $this->audit->record('reporting.gam_correction.superseded', $current->organization_id, $actor, $current,
                        oldValues: ['status' => $previous], newValues: ['status' => 'SUPERSEDED'],
                        metadata: ['financial_rows_changed' => false]);
                }
                return $current->context;
            }));
        return $this->start(Site::query()->findOrFail($context['site_id']), $context['from'], $context['to'], $actor);
    }

    public function poll(GamRevenueCorrection $candidate, User $actor): GamRevenueCorrection
    {
        $this->owned($candidate, $actor);
        return Cache::lock('gam-correction:'.$candidate->id, 180)->block(5, function () use ($candidate, $actor) {
            $candidate->refresh();
            $this->owned($candidate, $actor);
            if ($candidate->status !== 'PENDING') return $candidate;
            $this->unexpired($candidate);
            $attempt = self::hash($candidate->job);
            try {
                $this->assertEvidence($candidate);
                $job = $this->reports->poll($candidate->context, $candidate->job);
                $this->assertEvidence($candidate);
                if ($job['status'] !== 'COMPLETED') {
                    return $this->transition($candidate->id, 'PENDING', $attempt, ['job' => $job]);
                }
                $proposal = $this->proposal($candidate->context, $candidate->snapshot, $job['result']);
                $candidate->fill(['job' => $job, 'proposal' => $proposal, 'status' => $proposal['eligible'] ? 'READY' : 'BLOCKED']);
                $candidate->digest = $this->digest($candidate);
                return $this->transition($candidate->id, 'PENDING', $attempt, [
                    'job' => $job, 'proposal' => $proposal, 'status' => $candidate->status, 'digest' => $candidate->digest,
                ]);
            } catch (\Throwable $error) {
                return $this->transition($candidate->id, 'PENDING', $attempt,
                    ['status' => 'FAILED', 'error_code' => GamRevenueComparisonService::errorCode($error)]);
            }
        });
    }

    /** External calls cannot let a stale request replace newer durable evidence. */
    private function transition(string $id, string $expectedStatus, ?string $expectedJob, array $attributes): GamRevenueCorrection
    {
        return DB::transaction(function () use ($id, $expectedStatus, $expectedJob, $attributes) {
            $current = GamRevenueCorrection::lockForUpdate()->findOrFail($id);
            if ($current->status !== $expectedStatus
                || ($expectedJob !== null && ! hash_equals($expectedJob, self::hash($current->job)))) return $current;
            $current->update($attributes);
            return $current->refresh();
        });
    }

    public function apply(GamRevenueCorrection $candidate, string $digest, string $reason, User $actor): GamRevenueCorrection
    {
        $this->owned($candidate, $actor);
        $reason = trim($reason);
        if (! preg_match('/^[a-f0-9]{64}$/D', $digest) || mb_strlen($reason) < 12 || mb_strlen($reason) > 2000) {
            $this->invalid('Review the exact candidate digest and enter a specific correction reason.');
        }
        return Cache::lock('site-gam-report:'.$candidate->source_connection_id, 300)->block(5,
            fn () => Cache::lock('gam-correction:'.$candidate->id, 180)->block(5,
                fn () => DB::transaction(function () use ($candidate, $digest, $reason, $actor) {
                    $candidate = GamRevenueCorrection::lockForUpdate()->findOrFail($candidate->id);
                    $this->owned($candidate, $actor);
                    if (! is_string($candidate->digest) || ! hash_equals($candidate->digest, $digest)
                        || ! hash_equals($this->digest($candidate), $digest)) $this->invalid('The reviewed candidate digest does not match.');
                    if ($candidate->status === 'APPLIED') {
                        $receipt = GamRevenueCorrectionReceipt::where('correction_id', $candidate->id)->lockForUpdate()->firstOrFail();
                        if (! hash_equals($receipt->digest, $digest)) $this->invalid('The existing correction receipt does not match.');
                        return $candidate;
                    }
                    $this->unexpired($candidate);
                    if ($candidate->status !== 'READY' || ! ($candidate->proposal['eligible'] ?? false)) $this->invalid('This candidate is not eligible for application.');
                    $context = $candidate->context;
                    // Match normal importer lock ordering. Month close holds
                    // this same period lock through all downstream snapshots.
                    GamConnection::withoutGlobalScopes()->whereKey($context['gam_connection_id'])->lockForUpdate()->firstOrFail();
                    Site::withoutGlobalScopes()->whereKey($context['site_id'])->lockForUpdate()->firstOrFail();
                    SiteGamReportBinding::withoutGlobalScopes()->whereKey($context['binding_id'])->lockForUpdate()->firstOrFail();
                    $connection = ReportSourceConnection::withoutGlobalScopes()->whereKey($context['source_connection_id'])->lockForUpdate()->firstOrFail();
                    $source = ReportSource::withoutGlobalScopes()->whereKey($context['report_source_id'])->lockForUpdate()->firstOrFail();
                    $connection->setRelation('source', $source);
                    $this->lockOriginalEvidence($candidate);
                    $this->assertEvidence($candidate, lock: true);
                    $before = $this->capture($context, lock: true);
                    $proposal = $this->proposal($context, $before, $candidate->job['result']);
                    if (! $proposal['eligible'] || ! hash_equals(self::hash($proposal), self::hash($candidate->proposal))) {
                        $this->invalid('The approved daily changes are no longer valid. Prepare a new candidate.');
                    }
                    $eligibility = $this->settlement->forImport($connection, ReportFinality::Finalized, 'API',
                        CarbonImmutable::parse($context['from']), CarbonImmutable::parse($context['to']));
                    if (! $eligibility['eligible']) $this->invalid('This source is not eligible for finalized reporting.');
                    $job = ReportImportJob::withoutGlobalScopes()->create([
                        'organization_id' => $context['organization_id'], 'report_source_connection_id' => $connection->id,
                        'financial_period_id' => $before['facts'][0]['fact']['financial_period_id'],
                        'import_type' => 'API_CORRECTION', 'granularity' => ReportGranularity::Daily,
                        'finality' => ReportFinality::Finalized, 'settlement_eligible' => true,
                        'status' => ReportImportStatus::Processing,
                        'period_start' => $context['from'].' 00:00:00', 'period_end' => $context['to'].' 23:59:59',
                        'external_report_id' => 'gam-correction:'.$candidate->job['id'].':'.$digest,
                        'idempotency_key' => self::hash(['approved_correction' => $candidate->id, 'digest' => $digest]),
                        'checksum' => self::hash($candidate->job['result']), 'attempt_count' => 1,
                        'created_by' => $actor->id, 'started_at' => now(),
                    ]);
                    $sourceTotals = $storedTotals = array_fill_keys(['ad_requests', 'matched_requests', 'impressions', 'clicks', 'gross_revenue_minor'], 0);
                    $allocationTotals = array_fill_keys(['demand_partner_deductions_minor', 'invalid_traffic_adjustments_minor',
                        'other_adjustments_minor', 'net_revenue_minor', 'publisher_earnings_minor', 'horus_earnings_minor', 'mcm_partner_earnings_minor'], 0);
                    foreach ($proposal['days'] as $day) {
                        $fact = DailyReport::withoutGlobalScopes()->lockForUpdate()->findOrFail($day['original_fact_id']);
                        $metrics = $this->metrics($day['fresh'], $day['projected']);
                        $dimension = $this->dimensions->resolve([
                            'organization_id' => $context['organization_id'], 'publisher_id' => $context['publisher_id'],
                            'site_id' => $context['site_id'], 'gam_connection_id' => $context['gam_connection_id'],
                            'gam_ad_unit_id' => $context['ad_unit_id'], 'gam_report_site' => $context['hostname'],
                            'gam_report_basis' => SiteGamReportMetrics::BASIS,
                            'gam_report_scope' => self::hash(['historical_correction_scope' => 1,
                                'binding_fingerprint' => $context['binding_fingerprint'], 'query_hash' => $candidate->query_hash]),
                        ], $context['organization_id']);
                        $fact->update($metrics + [
                            'report_dimension_id' => $dimension->id, 'report_import_job_id' => $job->id,
                            'finality' => ReportFinality::Finalized, 'settlement_eligible' => true,
                            'revenue_rule_version_id' => $day['original_rule_version_id'],
                            'revision' => (int) $fact->revision + 1,
                            'source_row_hash' => self::hash(['correction' => $candidate->id, 'digest' => $digest,
                                'date' => $day['date'], 'dimension' => $dimension->dimension_hash, 'metrics' => $metrics]),
                        ]);
                        foreach ($sourceTotals as $field => $_) {
                            $sourceTotals[$field] += (int) $day['fresh'][$field];
                            $storedTotals[$field] += (int) $fact->getAttribute($field);
                        }
                        foreach ($allocationTotals as $field => $_) $allocationTotals[$field] += (int) $fact->getAttribute($field);
                    }
                    if ($sourceTotals !== $storedTotals) $this->invalid('Correction reconciliation failed; no financial changes were committed.');
                    $job->update(['status' => ReportImportStatus::Completed, 'row_count' => count($proposal['days']),
                        'inserted_count' => 0, 'updated_count' => count($proposal['days']), 'duplicate_count' => 0,
                        'source_totals' => $sourceTotals, 'normalized_totals' => $storedTotals + $allocationTotals, 'completed_at' => now()]);
                    $reconciliation = $this->reconciliation->forImport($job, $storedTotals, $sourceTotals, $actor);
                    $after = $this->capture($context, lock: true);
                    $receipt = GamRevenueCorrectionReceipt::create([
                        'correction_id' => $candidate->id, 'organization_id' => $context['organization_id'], 'approved_by' => $actor->id,
                        'report_import_job_id' => $job->id, 'reconciliation_run_id' => $reconciliation->id,
                        'digest' => $digest, 'query_hash' => $candidate->query_hash, 'reason' => $reason,
                        'before_hash' => $before['fingerprint'], 'after_hash' => $after['fingerprint'],
                        'context' => $context + ['google_report_job_id' => $candidate->job['id'],
                            'report_completed_at' => $candidate->job['completed_at'], 'query' => $this->reports->query($context)],
                        'before' => $before, 'after' => $after, 'applied_at' => now(),
                    ]);
                    $candidate->update(['status' => 'APPLIED', 'applied_at' => $receipt->applied_at,
                        'receipt' => $receipt->only(['id', 'correction_id', 'digest', 'approved_by', 'reason', 'applied_at',
                            'report_import_job_id', 'reconciliation_run_id', 'before_hash', 'after_hash', 'before', 'after'])]);
                    $this->audit->record('reporting.gam_correction.applied', $context['organization_id'], $actor, $receipt,
                        oldValues: ['fingerprint' => $before['fingerprint']], newValues: ['fingerprint' => $after['fingerprint']],
                        metadata: ['candidate_id' => $candidate->id, 'digest' => $digest, 'rows' => count($proposal['days']),
                            'statements_changed' => false, 'payments_changed' => false, 'forward_scope_changed' => false]);
                    return $candidate->refresh();
                })));
    }

    private function proposal(array $context, array $snapshot, array $fresh): array
    {
        $result = $this->comparisons->compare($context, $snapshot, $fresh, allowCompletedDayEstimates: true);
        $result['basis_notice'] = 'Stored Total-era or unversioned amounts can differ in both metric basis and site scope. The fresh evidence uses exact-site Ad Exchange; the application status and receipt record whether these changes were approved and committed.';
        $flags = $this->financeFlags($snapshot);
        $totals = array_fill_keys(['stored_gross_minor', 'new_gross_minor', 'stored_publisher_minor', 'new_publisher_minor', 'stored_horus_minor', 'new_horus_minor'], 0);
        foreach ($result['days'] as &$day) {
            $facts = array_values(array_filter($snapshot['facts'], fn ($row) => substr($row['fact']['report_date'], 0, 10) === $day['date']));
            $day['original_fact_id'] = count($facts) === 1 ? $facts[0]['fact']['id'] : null;
            $day['original_finality'] = count($facts) === 1 ? $facts[0]['fact']['finality'] : null;
            $day['proposed_finality'] = 'FINALIZED';
            if (count($facts) === 1 && ((int) ($facts[0]['fact']['video_starts'] ?? 0) !== 0
                || (int) ($facts[0]['fact']['completed_views'] ?? 0) !== 0)) {
                $day['flags'][] = 'UNVERIFIED_STORED_VIDEO_METRICS';
                $day['projected'] = null;
            }
            $flags = array_merge($flags, $day['flags']);
            if ($day['projected'] === null) $flags[] = 'UNVERIFIED_DAY_ALLOCATION';
            $totals['stored_gross_minor'] += (int) ($day['stored']['gross_revenue_minor'] ?? 0);
            $totals['new_gross_minor'] += (int) ($day['fresh']['gross_revenue_minor'] ?? 0);
            $totals['stored_publisher_minor'] += (int) ($day['stored']['publisher_earnings_minor'] ?? 0);
            $totals['new_publisher_minor'] += (int) ($day['projected']['publisher_earnings_minor'] ?? 0);
            $totals['stored_horus_minor'] += (int) ($day['stored']['horus_earnings_minor'] ?? 0);
            $totals['new_horus_minor'] += (int) ($day['projected']['horus_earnings_minor'] ?? 0);
        }
        unset($day);
        $result['flags'] = array_values(array_unique($flags));
        $result['eligible'] = $result['flags'] === [] && $result['days'] !== [];
        // Incomplete candidates never display partial new totals as a proposed
        // whole-range correction. Observed per-day evidence remains visible.
        $result['totals'] = $result['eligible'] ? $totals : null;
        return $result;
    }

    private function capture(array $context, bool $lock = false): array
    {
        $snapshot = $this->comparisons->snapshot($context, $lock);
        unset($snapshot['fingerprint']);
        $snapshot['hourly'] = HourlyReport::withoutGlobalScopes()->where('organization_id', $context['organization_id'])
            ->where(fn ($q) => $q->where('report_source_connection_id', $context['source_connection_id'])
                ->orWhereHas('dimension', fn ($q) => $q->where('site_id', $context['site_id'])))
            ->whereDate('report_date', '>=', $context['from'])->whereDate('report_date', '<=', $context['to'])
            ->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->limit(129)->get()->map(fn ($row) => $row->getAttributes())->all();
        $periods = FinancialPeriod::withoutGlobalScopes()->where('currency', 'USD')
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $context['organization_id']))
            ->whereDate('starts_on', '<=', $context['to'])->whereDate('ends_on', '>=', $context['from'])->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())->limit(129)->get();
        $ids = $periods->pluck('id')->all();
        $snapshot['finance']['periods'] = $periods->map(fn ($row) => $row->getAttributes())->all();
        $snapshot['finance']['monthly'] = DB::table('monthly_reports')->whereIn('financial_period_id', $ids)->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())->limit(129)->get(['id', 'financial_period_id', 'snapshot_hash'])->map(fn ($r) => (array) $r)->all();
        $statements = DB::table('publisher_statements')->where('publisher_id', $context['publisher_id'])->where('currency', 'USD')
            ->whereIn('financial_period_id', $ids)->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->limit(129)
            ->get(['id', 'financial_period_id', 'status', 'paid_minor', 'finalized_at', 'snapshot_hash', 'updated_at']);
        $snapshot['finance']['statements'] = $statements->map(fn ($r) => (array) $r)->all();
        $payments = DB::table('publisher_payments')->whereIn('publisher_statement_id', $statements->pluck('id'))->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())->limit(129)
            ->get(['id', 'publisher_statement_id', 'status', 'currency', 'amount_minor', 'settled_amount_minor', 'paid_at', 'updated_at']);
        $snapshot['finance']['payments'] = $payments->map(fn ($r) => (array) $r)->all();
        $snapshot['finance']['settlements'] = DB::table('publisher_payment_settlements')->whereIn('publisher_payment_id', $payments->pluck('id'))->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())->limit(129)
            ->get(['id', 'publisher_payment_id', 'amount_minor', 'currency', 'settled_on'])->map(fn ($r) => (array) $r)->all();
        $snapshot['finance']['affiliates'] = DB::table('publisher_affiliate_commissions')->whereIn('financial_period_id', $ids)
            ->where(fn ($q) => $q->where('referred_publisher_id', $context['publisher_id'])->orWhere('referrer_publisher_id', $context['publisher_id']))
            ->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->limit(129)
            ->get(['id', 'financial_period_id', 'source_publisher_statement_id', 'status', 'commission_minor', 'currency'])->map(fn ($r) => (array) $r)->all();
        $snapshot['finance']['adjustments'] = DB::table('revenue_adjustments')->whereIn('financial_period_id', $ids)
            ->where(fn ($q) => $q->where('publisher_id', $context['publisher_id'])->orWhere('site_id', $context['site_id'])
                ->orWhere('report_source_connection_id', $context['source_connection_id'])->orWhereNull('publisher_id'))
            ->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->limit(129)
            ->get(['id', 'financial_period_id', 'status', 'currency', 'amount_minor', 'publisher_id', 'site_id', 'report_source_connection_id', 'updated_at'])->map(fn ($r) => (array) $r)->all();
        $snapshot['finance']['active_imports'] = DB::table('report_import_jobs')->where('report_source_connection_id', $context['source_connection_id'])
            ->whereIn('status', ['PENDING', 'PROCESSING'])->whereDate('period_start', '<=', $context['to'])->whereDate('period_end', '>=', $context['from'])
            ->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->limit(129)
            ->get(['id', 'status', 'period_start', 'period_end'])->map(fn ($r) => (array) $r)->all();
        $snapshot['fingerprint'] = self::hash($snapshot);
        return $snapshot;
    }

    private function financeFlags(array $snapshot): array
    {
        $flags = [];
        if ($snapshot['hourly']) $flags[] = 'HOURLY_FACTS_REQUIRE_SEPARATE_REVIEW';
        if (count($snapshot['finance']['periods']) !== 1) $flags[] = 'AMBIGUOUS_FINANCIAL_PERIOD';
        foreach ($snapshot['finance']['periods'] as $period) if ($period['status'] !== 'OPEN') $flags[] = 'PERIOD_NOT_OPEN';
        foreach (['monthly', 'statements', 'payments', 'settlements', 'affiliates', 'active_imports'] as $kind) {
            if ($snapshot['finance'][$kind]) $flags[] = strtoupper($kind).'_BLOCK_CORRECTION';
        }
        foreach ($snapshot['finance']['adjustments'] as $adjustment) {
            if ($adjustment['status'] === 'PENDING') $flags[] = 'PENDING_ADJUSTMENTS';
        }
        if (count($snapshot['finance']['adjustments']) > 128) $flags[] = 'TOO_MANY_ADJUSTMENTS';
        return array_values(array_unique($flags));
    }

    private function lockOriginalEvidence(GamRevenueCorrection $candidate): void
    {
        $context = $candidate->context;
        // Adjustment approval takes its row before the period. Match that
        // order; the period then also excludes newly created adjustments.
        DB::table('revenue_adjustments')->whereIn('id', array_column($candidate->snapshot['finance']['adjustments'], 'id'))->orderBy('id')->lockForUpdate()->get();
        FinancialPeriod::withoutGlobalScopes()->where('currency', 'USD')
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $context['organization_id']))
            ->whereDate('starts_on', '<=', $context['to'])->whereDate('ends_on', '>=', $context['from'])->orderBy('id')->lockForUpdate()->get();
        $facts = $candidate->snapshot['facts'];
        DailyReport::withoutGlobalScopes()->whereIn('id', array_column(array_column($facts, 'fact'), 'id'))->orderBy('id')->lockForUpdate()->get();
        ReportDimension::withoutGlobalScopes()->whereIn('id', array_column(array_column($facts, 'dimension'), 'id'))->orderBy('id')->lockForUpdate()->get();
        RevenueRule::withoutGlobalScopes()->whereIn('id', array_column(array_column($facts, 'rule'), 'id'))->orderBy('id')->lockForUpdate()->get();
        RevenueRuleVersion::withoutGlobalScopes()->whereIn('id', array_column(array_column($facts, 'rule_version'), 'id'))->orderBy('id')->lockForUpdate()->get();
    }

    private function assertEvidence(GamRevenueCorrection $candidate, bool $lock = false): void
    {
        $this->assertHistoricalWindow($candidate->context, $lock);
        if (! hash_equals($candidate->query_hash, $this->reports->queryHash($candidate->context))
            || ! hash_equals($candidate->snapshot['fingerprint'], $this->capture($candidate->context, $lock)['fingerprint'])) {
            $this->invalid('Stored facts, original rules, source scope or financial state changed. Prepare a new candidate.');
        }
    }

    private function assertHistoricalWindow(array $context, bool $lock = false): void
    {
        $binding = $this->comparisons->assertContext($context, $lock);
        $scope = data_get($binding->connection->configuration, 'site_report_scope');
        if (! is_array($scope)) $this->invalid('A verified forward Ad Exchange scope must exist before historical correction.');
        $this->scopes->assertCurrent($binding, $scope, $lock);
        if ($context['to'] >= $scope['effective_from']) {
            $this->invalid('Historical corrections must end before the unchanged forward reporting cutover. Current-scope days use normal synchronization.');
        }
    }

    private function metrics(array $fresh, array $financial): array
    {
        $metrics = array_intersect_key($fresh, array_flip(['ad_requests', 'matched_requests', 'impressions', 'clicks', ...PerformanceMetrics::COUNTERS]));
        $metrics['unfilled_requests'] = max(0, $metrics['ad_requests'] - $metrics['matched_requests']);
        $metrics['unfilled_impressions'] = null;
        $metrics['video_starts'] = $metrics['completed_views'] = 0;
        $metrics['viewability_bp'] = app(PerformanceMetrics::class)->viewability($metrics);
        return array_merge($metrics, $financial, $this->calculator->rates($metrics + $financial));
    }

    private function digest(GamRevenueCorrection $candidate): string
    {
        return self::hash(['version' => 1, 'candidate_id' => $candidate->id, 'actor_id' => $candidate->actor_id,
            'context' => $candidate->context, 'snapshot' => $candidate->snapshot['fingerprint'],
            'query_hash' => $candidate->query_hash, 'job' => $candidate->job, 'proposal' => $candidate->proposal]);
    }

    /** MySQL may reorder every JSON object's keys. Array order remains meaningful. */
    public static function hash(array $value): string
    {
        $canonical = function (mixed $item) use (&$canonical): mixed {
            if (! is_array($item)) return $item;
            if (! array_is_list($item)) ksort($item);
            return array_map($canonical, $item);
        };
        return hash('sha256', json_encode($canonical($value), JSON_THROW_ON_ERROR));
    }

    private function owned(GamRevenueCorrection $candidate, User $actor): void
    {
        $this->authorize($actor);
        abort_unless($candidate->actor_id === $actor->id, 404);
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->isHorusAdministrator() && $actor->hasPermission('reporting.admin.view')
            && $actor->hasPermission('reporting.import') && $actor->hasPermission('finance.adjustments.approve'), 403);
    }

    private function unexpired(GamRevenueCorrection $candidate): void
    {
        if ($candidate->expires_at->lte(now())) $this->invalid('This candidate expired. Prepare a fresh candidate before review.');
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['correction' => $message]);
    }
}
