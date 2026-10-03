<?php

namespace App\Services\Reporting;

use App\Models\GamRevenueCorrection;
use App\Models\RevenueRuleVersion;
use Carbon\CarbonImmutable;

/** Independent, private evidence checks. READY alone is never approval. */
final class GamHistoricalOperationReview
{
    public function __construct(
        private readonly GamHistoricalCorrectionReport $reports,
        private readonly GamRevenueComparisonService $comparisons,
        private readonly RevenueCalculator $calculator,
        private readonly SiteGamReportScope $scopes,
    ) {}

    public function review(GamRevenueCorrection $candidate, array $window): array
    {
        $checks = array_fill_keys(['candidate_digest', 'currency', 'unit', 'hostname', 'days', 'original_rules',
            'arithmetic', 'reconciliation', 'current_facts', 'financial_state', 'pre_cutover'], false);
        try {
            $context = $candidate->context;
            $proposal = $candidate->proposal;
            $fresh = $candidate->job['result'] ?? [];
            $binding = $this->comparisons->assertContext($context);
            $scope = data_get($binding->connection->configuration, 'site_report_scope');
            $this->scopes->assertCurrent($binding, $scope);
            $query = $this->reports->query($context);
            $checks['candidate_digest'] = is_string($candidate->digest) && hash_equals($candidate->digest,
                GamRevenueCorrectionService::hash(['version' => 1, 'candidate_id' => $candidate->id, 'actor_id' => $candidate->actor_id,
                    'context' => $context, 'snapshot' => $candidate->snapshot['fingerprint'], 'query_hash' => $candidate->query_hash,
                    'job' => $candidate->job, 'proposal' => $proposal]));
            $checks['currency'] = $context['currency'] === 'USD' && $query['reportCurrency'] === 'USD'
                && $binding->connection->currency === 'USD'
                && in_array($candidate->job['confirmed_currency'], [null, 'USD'], true);
            $checks['unit'] = $context['binding_id'] === $window['binding_id'] && $context['site_id'] === $window['site_id']
                && $context['source_connection_id'] === $window['source_connection_id']
                && $context['ad_unit_id'] === $binding->ad_unit_id && $context['metric_basis'] === SiteGamReportMetrics::BASIS
                && $query['dimensions'] === ['DATE', 'AD_UNIT_ID', 'SITE_NAME'] && $query['adUnitView'] === 'FLAT'
                && $query['statement']['query'] === 'WHERE AD_UNIT_ID = :unit'
                && $query['statement']['values'][0]['value']['value'] === $binding->ad_unit_id
                && hash_equals($candidate->query_hash, $this->reports->queryHash($context));
            $checks['hostname'] = $context['hostname'] === $this->scopes->hostname($binding->site->primary_domain)
                && $scope['hostname'] === $context['hostname'] && ($fresh['exact_site_observed'] ?? false) === true;
            $dates = [];
            for ($day = CarbonImmutable::parse($window['from']); $day->toDateString() <= $window['to']; $day = $day->addDay()) $dates[] = $day->toDateString();
            $observed = array_keys($fresh['days'] ?? []); sort($observed);
            $ids = array_column($proposal['days'] ?? [], 'original_fact_id'); sort($ids);
            $expectedIds = $window['fact_ids']; sort($expectedIds);
            $checks['days'] = $dates === $observed && $dates === array_column($proposal['days'] ?? [], 'date')
                && $ids === $expectedIds && $context['from'] === $window['from'] && $context['to'] === $window['to'];
            $checks['pre_cutover'] = substr($window['from'], 0, 7) === substr($window['to'], 0, 7)
                && $window['from'] >= $binding->starts_on->toDateString() && $window['to'] < $scope['effective_from']
                && (! $binding->ends_on || $window['to'] <= $binding->ends_on->toDateString())
                && $window['to'] < CarbonImmutable::now($context['timezone'])->toDateString();
            $checks['current_facts'] = GamRevenueCorrectionService::hash($candidate->snapshot['facts'])
                === GamRevenueCorrectionService::hash($this->comparisons->snapshot($context)['facts']);
            $finance = $candidate->snapshot['finance'];
            $checks['financial_state'] = $candidate->snapshot['hourly'] === [] && count($finance['periods']) === 1
                && $finance['periods'][0]['status'] === 'OPEN';
            foreach (['monthly', 'statements', 'payments', 'settlements', 'affiliates', 'active_imports'] as $kind) {
                $checks['financial_state'] = $checks['financial_state'] && $finance[$kind] === [];
            }
            foreach ($finance['adjustments'] as $adjustment) $checks['financial_state'] = $checks['financial_state'] && $adjustment['status'] !== 'PENDING';
            $checks['original_rules'] = $checks['arithmetic'] = true;
            // Re-evaluate original rule scope, validity dates, shares, currency and
            // stored allocation instead of trusting the saved eligible flag.
            $recomputedComparison = $this->comparisons->compare($context, $candidate->snapshot, $fresh, allowCompletedDayEstimates: true);
            foreach ($recomputedComparison['days'] as $day) {
                $checks['original_rules'] = $checks['original_rules'] && $day['flags'] === [];
            }
            $totals = array_fill_keys(['stored_gross_minor', 'new_gross_minor', 'stored_publisher_minor', 'new_publisher_minor', 'stored_horus_minor', 'new_horus_minor'], 0);
            foreach ($proposal['days'] ?? [] as $day) {
                $rows = array_values(array_filter($candidate->snapshot['facts'], fn ($row) => $row['fact']['id'] === $day['original_fact_id']));
                if (count($rows) !== 1 || ! $rows[0]['rule_version'] || ! $day['projected'] || $day['flags'] !== []) throw new \RuntimeException;
                $row = $rows[0]; $fact = $row['fact'];
                $version = new RevenueRuleVersion;
                $version->setRawAttributes($row['rule_version']);
                $checks['original_rules'] = $checks['original_rules'] && $day['original_rule_version_id'] === $fact['revenue_rule_version_id']
                    && $version->id === $fact['revenue_rule_version_id'] && $row['rule']['id'] === $version->revenue_rule_id;
                $original = $this->calculator->calculate((int) $fact['gross_revenue_minor'], (int) $fact['demand_partner_deductions_minor'],
                    (int) $fact['invalid_traffic_adjustments_minor'], (int) $fact['other_adjustments_minor'], $version);
                $projected = $this->calculator->calculate($day['fresh']['gross_revenue_minor'], (int) $fact['demand_partner_deductions_minor'],
                    (int) $fact['invalid_traffic_adjustments_minor'], (int) $fact['other_adjustments_minor'], $version);
                foreach ($original as $field => $value) $checks['original_rules'] = $checks['original_rules'] && (int) $fact[$field] === $value;
                $micros = $day['fresh']['revenue_micros'];
                $checks['arithmetic'] = $checks['arithmetic'] && is_int($micros)
                    && $day['fresh']['gross_revenue_minor'] === ($micros < 0 ? -1 : 1) * intdiv(abs($micros) + 5000, 10000)
                    && GamRevenueCorrectionService::hash($day['fresh']) === GamRevenueCorrectionService::hash($fresh['days'][$day['date']])
                    && GamRevenueCorrectionService::hash($projected) === GamRevenueCorrectionService::hash($day['projected'])
                    && $projected['net_revenue_minor'] === $projected['publisher_earnings_minor'] + $projected['horus_earnings_minor'] + $projected['mcm_partner_earnings_minor'];
                foreach (['gross' => 'gross_revenue_minor', 'publisher' => 'publisher_earnings_minor', 'horus' => 'horus_earnings_minor'] as $name => $field) {
                    $totals['stored_'.$name.'_minor'] += (int) $fact[$field];
                    $totals['new_'.$name.'_minor'] += $projected[$field];
                }
            }
            $checks['reconciliation'] = GamRevenueCorrectionService::hash($totals) === GamRevenueCorrectionService::hash($proposal['totals'] ?? [])
                && ($proposal['eligible'] ?? false) === true && ($proposal['flags'] ?? null) === []
                && count($proposal['days'] ?? []) === count($window['fact_ids']) && $candidate->status === 'READY';
        } catch (\Throwable) {
            // No raw exceptions or private data cross the operational boundary.
        }
        return ['version' => 1, 'passed' => ! in_array(false, $checks, true), 'checks' => $checks,
            'candidate_digest' => $candidate->digest, 'evidence_hash' => GamRevenueCorrectionService::hash([
                $candidate->context, $candidate->snapshot, $candidate->job, $candidate->proposal,
            ])];
    }
}
