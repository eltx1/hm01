<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\DailyReport;
use App\Models\FinancialPeriod;
use App\Models\GamConnection;
use App\Models\GamRevenueCorrection;
use App\Models\GamRevenueCorrectionReceipt;
use App\Models\HourlyReport;
use App\Models\ReportImportJob;
use App\Models\ReportSource;
use App\Models\ReportSourceConnection;
use App\Models\RevenueRule;
use App\Models\RevenueRuleVersion;
use App\Models\SiteGamReportBinding;
use App\Services\Reporting\GamAdUnitReportClient;
use App\Services\Reporting\GamHistoricalOperation;
use App\Services\Reporting\GamRevenueCorrectionService;
use App\Services\Reporting\ReportDimensionResolver;
use App\Services\Reporting\SiteGamReportMetrics;
use App\Services\Reporting\SiteGamReportScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

final class GamHistoricalOneTimeOperationTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private array $operationFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->operationFiles as $file) {
            // Only this test's cryptographically random operation files are removed.
            foreach (glob($file.'*') ?: [] as $ownedFile) {
                if (is_file($ownedFile)) unlink($ownedFile);
            }
        }

        parent::tearDown();
    }

    public function test_restarted_ticks_apply_once_to_the_same_daily_ids_with_original_rules_and_stable_receipts(): void
    {
        $fixture = $this->context();
        $contract = $this->contract($fixture);
        $ids = $fixture['facts']->pluck('id')->sort()->values()->all();
        $sourceBefore = $fixture['connection']->fresh()->getAttributes();
        $forwardJobBefore = $fixture['job']->fresh()->getAttributes();

        $this->advanceUntil($contract, fn () => GamRevenueCorrectionReceipt::query()->count() === 1);

        $this->assertSame($ids, DailyReport::withoutGlobalScopes()->orderBy('id')->pluck('id')->all());
        foreach ($fixture['facts'] as $original) {
            $fact = $original->fresh();
            $this->assertSame(8, (int) $fact->revision);
            $this->assertSame(201, (int) $fact->gross_revenue_minor);
            $this->assertSame(133, (int) $fact->publisher_earnings_minor);
            $this->assertSame($fixture['version']->id, $fact->revenue_rule_version_id);
        }
        $this->assertSame($sourceBefore, $fixture['connection']->fresh()->getAttributes());
        $this->assertSame($forwardJobBefore, $fixture['job']->fresh()->getAttributes());
        $receipt = GamRevenueCorrectionReceipt::query()->sole()->getAttributes();
        $after = $this->financialSnapshot();
        $calls = $fixture['google']->calls;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $result = $this->tick($contract);
            $this->assertSame(2, $result['counts']['corrected_facts']);
            $this->assertSame(0, $result['counts']['pending']);
            $this->assertSame($after, $this->financialSnapshot());
            $this->assertSame($receipt, GamRevenueCorrectionReceipt::query()->sole()->getAttributes());
            $this->assertSame($calls, $fixture['google']->calls);
        }
        $this->assertDatabaseCount('gam_revenue_corrections', 1);
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 1);
        $this->assertDatabaseCount('report_import_jobs', 2);
        $this->assertDatabaseCount('reconciliation_runs', 1);
        $this->assertDatabaseCount('monthly_reports', 0);
        $this->assertDatabaseCount('publisher_statements', 0);
        $this->assertDatabaseCount('publisher_payments', 0);
        Http::assertNothingSent();
    }

    public function test_one_tick_never_applies_more_than_one_eligible_window(): void
    {
        $fixture = $this->context(['2026-09-01', '2026-09-03', '2026-09-05']);
        $contract = $this->contract($fixture);

        $this->advanceUntil($contract, fn () => GamRevenueCorrectionReceipt::query()->count() === 3);

        $this->assertDatabaseCount('gam_revenue_corrections', 3);
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 3);
        $this->assertCount(3, $fixture['google']->queries);
        foreach ($fixture['facts'] as $fact) $this->assertSame(8, (int) $fact->fresh()->revision);
        $this->assertSame(3, $this->tick($contract)['counts']['corrected_facts']);
    }

    public function test_seven_sparse_windows_preserve_receipts_at_capacity_and_resume_after_parent_expiry(): void
    {
        $dates = $missingDays = [];
        for ($index = 0; $index < 7; $index++) {
            $dates[] = sprintf('2026-09-%02d', 1 + 3 * $index);
            $dates[] = $missingDays[] = sprintf('2026-09-%02d', 2 + 3 * $index);
        }
        $fixture = $this->context($dates);
        $fixture['google']->missingDays = $missingDays;
        $contract = $this->contract($fixture);
        $missingFacts = $fixture['facts']->filter(fn ($fact) => in_array($fact->report_date->toDateString(), $missingDays, true));
        $missingBefore = $missingFacts->map(fn ($fact) => $fact->getAttributes())->values()->all();
        $this->advanceUntil($contract, fn () => GamRevenueCorrection::query()->where('status', 'BLOCKED')->count() === 6);
        $blocked = $this->tick($contract);

        $this->assertSame('BLOCKED', $blocked['outcome']);
        $this->assertSame('CAPACITY_REACHED', $blocked['reason']);
        $this->assertSame(5, $blocked['counts']['corrected_facts']);
        $this->assertSame(6, GamRevenueCorrection::query()->where('expires_at', '>', now())
            ->whereNotIn('status', ['APPLIED', 'SUPERSEDED'])->count());
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 5);
        $this->assertDatabaseCount('gam_revenue_corrections', 11);
        $this->assertCount(11, $fixture['google']->queries);
        $beforeCapacityRetry = $this->financialSnapshot();
        $receiptsBefore = GamRevenueCorrectionReceipt::query()->orderBy('id')->get()->map(fn ($receipt) => $receipt->getAttributes())->all();
        $parents = GamRevenueCorrection::query()->where('status', 'BLOCKED')->get();
        $parentEvidence = $parents->mapWithKeys(fn ($parent) => [$parent->id => $this->candidateEvidence($parent)])->all();
        $calls = $fixture['google']->calls;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->assertSame('CAPACITY_REACHED', $this->tick($contract)['reason']);
            $this->assertSame($beforeCapacityRetry, $this->financialSnapshot());
            $this->assertSame($calls, $fixture['google']->calls);
            $this->assertDatabaseCount('gam_revenue_corrections', 11);
        }

        $this->travel(61)->minutes();
        $this->advanceUntil($contract, fn () => GamRevenueCorrectionReceipt::query()->count() === 7);
        $finished = $this->tick($contract);

        $this->assertSame('BLOCKED', $finished['outcome']);
        $this->assertSame('NONE', $finished['reason']);
        $this->assertSame(7, $finished['counts']['corrected_facts']);
        $this->assertSame(7, $finished['counts']['blocked_facts']);
        $this->assertSame(0, $finished['counts']['pending']);
        $this->assertSame(14, $finished['counts']['corrected_facts'] + $finished['counts']['blocked_facts']);
        $this->assertSame($missingBefore, $missingFacts->map(fn ($fact) => $fact->fresh()->getAttributes())->values()->all());
        foreach ($parents as $parent) {
            $this->assertSame('BLOCKED', $parent->fresh()->status);
            $this->assertSame($parentEvidence[$parent->id], $this->candidateEvidence($parent->fresh()));
        }
        foreach ($receiptsBefore as $receipt) {
            $this->assertSame($receipt, GamRevenueCorrectionReceipt::query()->findOrFail($receipt['id'])->getAttributes());
        }
        $this->assertDatabaseCount('gam_revenue_corrections', 14);
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 7);
        $this->assertDatabaseCount('daily_reports', 14);
        $this->assertCount(14, $fixture['google']->queries);
        $this->assertCount(14, array_unique($fixture['google']->downloadedJobs));
    }

    public function test_missing_middle_day_keeps_the_blocked_parent_and_corrects_only_fresh_contiguous_children(): void
    {
        $fixture = $this->context(['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-09-05']);
        $fixture['google']->missingDays = ['2026-09-03'];
        $contract = $this->contract($fixture);
        $missing = $fixture['facts']->first(fn ($fact) => $fact->report_date->toDateString() === '2026-09-03');
        $missingBefore = $missing->getAttributes();
        $before = $this->financialSnapshot();
        $this->advanceUntil($contract, fn () => GamRevenueCorrection::query()->where('status', 'BLOCKED')->exists());
        $parent = GamRevenueCorrection::query()->where('period_start', '2026-09-01')->where('period_end', '2026-09-05')->sole();
        $parentEvidence = $this->candidateEvidence($parent);

        $this->assertSame('BLOCKED', $parent->status);
        $this->assertNull($parent->proposal['totals']);
        $this->assertArrayNotHasKey('2026-09-03', $parent->job['result']['days']);
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 0);

        $this->advanceUntil($contract, fn () => GamRevenueCorrectionReceipt::query()->count() === 2);
        $result = $this->tick($contract);
        $children = GamRevenueCorrection::query()->whereKeyNot($parent->id)->orderBy('period_start')->get();

        $this->assertCount(2, $children);
        $this->assertSame([['2026-09-01', '2026-09-02'], ['2026-09-04', '2026-09-05']],
            $children->map(fn ($child) => [$child->period_start, $child->period_end])->all());
        $this->assertSame('BLOCKED', $parent->fresh()->status);
        $this->assertSame($parentEvidence, $this->candidateEvidence($parent->fresh()));
        $this->assertSame($missingBefore, $missing->fresh()->getAttributes());
        $this->assertCount(3, $fixture['google']->queries);
        $this->assertCount(3, array_unique($fixture['google']->downloadedJobs));
        foreach ($children as $child) {
            $this->assertSame('APPLIED', $child->status);
            $this->assertNotSame($parent->job['id'], $child->job['id']);
            $this->assertNotSame($parent->query_hash, $child->query_hash);
            $this->assertSame($child->period_start, $child->context['from']);
            $this->assertSame($child->period_end, $child->context['to']);
            $this->assertCount(2, $child->snapshot['facts']);
            $this->assertSame($child->digest, GamRevenueCorrectionReceipt::query()->where('correction_id', $child->id)->sole()->digest);
        }
        $manifest = $this->manifest($contract);
        $parentWindow = collect($manifest['windows'])->firstWhere('candidate_id', $parent->id);
        $this->assertSame('PARTITIONED', $parentWindow['state']);
        $this->assertSame($fixture['facts']->pluck('id')->all(), $parentWindow['fact_ids']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $parentWindow['partition']['evidence_hash']);
        foreach ($children as $child) {
            $childWindow = collect($manifest['windows'])->firstWhere('candidate_id', $child->id);
            $this->assertCount(1, $childWindow['ancestor_evidence']);
            $ancestor = $childWindow['ancestor_evidence'][0];
            $this->assertSame($parent->id, $ancestor['candidate_id']);
            $this->assertSame($parent->digest, $ancestor['digest']);
            $this->assertSame($parentWindow['partition']['evidence_hash'], $ancestor['evidence_hash']);
            $this->assertSame('2026-09-01', $ancestor['from']);
            $this->assertSame('2026-09-05', $ancestor['to']);
            $this->assertSame($parentWindow['fact_ids'], $ancestor['fact_ids']);
        }
        foreach ($fixture['facts'] as $fact) {
            $this->assertSame($fact->id === $missing->id ? 'BLOCKED' : 'CORRECTED', $manifest['coverage']['daily:'.$fact->id]['state']);
        }
        $this->assertSame(4, $result['counts']['corrected_facts']);
        $this->assertSame(1, $result['counts']['blocked_facts']);
        $this->assertSame(0, $result['counts']['pending']);
        $this->assertSame('BLOCKED', $result['outcome']);
        $this->assertSame(5, $result['counts']['corrected_facts'] + $result['counts']['blocked_facts']);
        $this->assertDatabaseCount('daily_reports', 5);
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 2);
    }

    public function test_observed_parent_rows_cannot_substitute_for_missing_fresh_child_report_rows(): void
    {
        $fixture = $this->context(['2026-09-01', '2026-09-02', '2026-09-03']);
        $fixture['google']->missingDays = ['2026-09-02'];
        // The parent observed September 1, but its new child report does not.
        $fixture['google']->missingDaysByJob['2'] = ['2026-09-01'];
        $contract = $this->contract($fixture);
        $firstBefore = $fixture['facts']->first()->getAttributes();
        $middleBefore = $fixture['facts'][1]->getAttributes();

        $this->advanceUntil($contract, fn () => GamRevenueCorrectionReceipt::query()->count() === 1);
        $result = $this->tick($contract);

        $this->assertSame($firstBefore, $fixture['facts']->first()->fresh()->getAttributes());
        $this->assertSame($middleBefore, $fixture['facts'][1]->fresh()->getAttributes());
        $this->assertSame(8, (int) $fixture['facts']->last()->fresh()->revision);
        $this->assertCount(3, $fixture['google']->queries);
        $this->assertSame(2, GamRevenueCorrection::query()->where('status', 'BLOCKED')->count());
        $this->assertSame(1, $result['counts']['corrected_facts']);
        $this->assertSame(2, $result['counts']['blocked_facts']);
        $this->assertSame('BLOCKED', $result['outcome']);
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 1);
    }

    public function test_partitioning_preserves_missing_and_invalid_days_as_distinct_unresolved_coverage(): void
    {
        $fixture = $this->context(['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-09-05']);
        $fixture['google']->missingDays = ['2026-09-03'];
        $invalid = $fixture['facts'][3];
        $invalid->update(['revenue_rule_version_id' => null]);
        $invalidBefore = $invalid->fresh()->getAttributes();
        $missingBefore = $fixture['facts'][2]->getAttributes();
        $contract = $this->contract($fixture);

        $this->advanceUntil($contract, fn () => GamRevenueCorrectionReceipt::query()->count() === 2);
        $result = $this->tick($contract);
        $manifest = $this->manifest($contract);

        $this->assertSame($invalidBefore, $invalid->fresh()->getAttributes());
        $this->assertSame($missingBefore, $fixture['facts'][2]->fresh()->getAttributes());
        $this->assertSame('NO_EXACT_SITE_ROW', $manifest['coverage']['daily:'.$fixture['facts'][2]->id]['reason']);
        $this->assertSame('UNVERIFIED_OBSERVED_DAY', $manifest['coverage']['daily:'.$invalid->id]['reason']);
        $this->assertSame(3, $result['counts']['corrected_facts']);
        $this->assertSame(2, $result['counts']['blocked_facts']);
        $this->assertSame('BLOCKED', $result['outcome']);
        $this->assertSame([['2026-09-01', '2026-09-02'], ['2026-09-05', '2026-09-05']],
            GamRevenueCorrection::query()->where('status', 'APPLIED')->orderBy('period_start')->get()
                ->map(fn ($candidate) => [$candidate->period_start, $candidate->period_end])->all());
        $this->assertDatabaseCount('daily_reports', 5);
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 2);
    }

    public function test_changed_parent_evidence_blocks_fresh_children_before_any_financial_apply(): void
    {
        $fixture = $this->context(['2026-09-01', '2026-09-02', '2026-09-03']);
        $fixture['google']->missingDays = ['2026-09-02'];
        $contract = $this->contract($fixture);
        $this->advanceUntil($contract, fn () => GamRevenueCorrection::query()->count() === 2);
        $parent = GamRevenueCorrection::query()->where('period_start', '2026-09-01')->where('period_end', '2026-09-03')->sole();
        $child = GamRevenueCorrection::query()->whereKeyNot($parent->id)->sole();
        $this->assertSame('PENDING', $child->status);
        $before = $this->financialSnapshot();
        $proposal = $parent->proposal;
        $proposal['basis_notice'] = 'Synthetic altered parent evidence after the child was planned.';
        // Simulate corrupt persisted evidence, bypassing the ordinary immutable
        // model guard. The child's ancestor hash must still detect the change.
        DB::table('gam_revenue_corrections')->where('id', $parent->id)->update([
            'proposal' => json_encode($proposal, JSON_THROW_ON_ERROR),
        ]);

        for ($attempt = 0; $attempt < 3; $attempt++) $result = $this->tick($contract);

        $this->assertSame('BLOCKED', $result['outcome']);
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 0);
        $this->assertDatabaseCount('gam_revenue_corrections', 2);
        $this->assertCount(2, $fixture['google']->queries);
    }

    public function test_no_exact_site_rows_is_fully_blocked_without_children_or_zero_revenue(): void
    {
        $fixture = $this->context();
        $fixture['google']->missingDays = ['2026-09-01', '2026-09-02'];
        $contract = $this->contract($fixture);
        $before = $this->financialSnapshot();
        $this->advanceUntil($contract, fn () => GamRevenueCorrection::query()->where('status', 'BLOCKED')->exists());

        for ($attempt = 0; $attempt < 3; $attempt++) $result = $this->tick($contract);

        $this->assertSame('BLOCKED', $result['outcome']);
        $this->assertSame(0, $result['counts']['corrected_facts']);
        $this->assertSame(2, $result['counts']['blocked_facts']);
        $this->assertSame(0, $result['counts']['pending']);
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertCount(1, $fixture['google']->queries);
        $this->assertDatabaseCount('gam_revenue_corrections', 1);
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 0);
    }

    public function test_after_bound_facts_are_excluded_and_normal_forward_changes_do_not_stale_the_operation(): void
    {
        $fixture = $this->context();
        $afterBound = $this->addDailyFact($fixture, '2026-10-03');
        $afterBoundHourly = $this->addHourlyFact($fixture, '2026-10-03');
        $forward = $this->addForwardFact($fixture, '2026-10-10');
        $contract = $this->contract($fixture);
        $this->advanceUntil($contract, fn () => GamRevenueCorrection::query()->where('status', 'PENDING')->exists());
        $afterBound->increment('gross_revenue_minor');
        $forward->increment('gross_revenue_minor');
        $newAfterBound = $this->addDailyFact($fixture, '2026-10-04');
        $newForward = $this->addForwardFact($fixture, '2026-10-10', hourly: true);
        $excluded = collect([$afterBound, $afterBoundHourly, $forward, $newAfterBound, $newForward])
            ->map(fn ($fact) => $fact->fresh()->getAttributes())->all();

        $this->advanceUntil($contract, fn () => GamRevenueCorrectionReceipt::query()->count() === 1);
        $result = $this->tick($contract);

        $this->assertSame(2, $result['counts']['daily_facts']);
        $this->assertSame(0, $result['counts']['hourly_facts']);
        $this->assertSame(2, $result['counts']['corrected_facts']);
        $this->assertSame(0, $result['counts']['blocked_facts']);
        $this->assertSame($excluded, collect([$afterBound, $afterBoundHourly, $forward, $newAfterBound, $newForward])
            ->map(fn ($fact) => $fact->fresh()->getAttributes())->all());
        $manifest = $this->manifest($contract);
        foreach ([$afterBound, $forward, $newAfterBound] as $fact) $this->assertArrayNotHasKey('daily:'.$fact->id, $manifest['records']);
        foreach ([$afterBoundHourly, $newForward] as $fact) $this->assertArrayNotHasKey('hourly:'.$fact->id, $manifest['records']);
        foreach ($fixture['google']->queries as $query) $this->assertSame(['year' => 2026, 'month' => 9, 'day' => 2], $query['endDate']);
    }

    public function test_new_in_bound_history_after_discovery_cannot_expand_the_immutable_operation(): void
    {
        $fixture = $this->context();
        $contract = $this->contract($fixture);
        $this->tick($contract);
        $this->addDailyFact($fixture, '2026-09-05');
        $before = $this->financialSnapshot();
        $queries = $fixture['google']->queries;

        $result = $this->tick($contract);

        $this->assertSame('BLOCKED', $result['outcome']);
        $this->assertSame('STALE_INVENTORY', $result['reason']);
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertSame($queries, $fixture['google']->queries);
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 0);
    }

    #[DataProvider('changedContractCases')]
    public function test_a_saved_operation_rejects_any_changed_finite_contract(string $field, mixed $value): void
    {
        $fixture = $this->context();
        $contract = $this->contract($fixture);
        $this->tick($contract);
        $before = $this->financialSnapshot();
        $calls = $fixture['google']->calls;
        $changed = array_replace($contract, [$field => $value]);

        $result = $this->tick($changed);

        $this->assertSame('BLOCKED', $result['outcome']);
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertSame($calls, $fixture['google']->calls);
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 0);
    }

    public static function changedContractCases(): array
    {
        return ['schema' => ['schema_version', 2], 'earlier bound' => ['through', '2026-10-01'],
            'later bound' => ['through', '2026-10-03'],
            'actor selector' => ['actor_selector', str_repeat('0', 64)]];
    }

    public function test_the_actor_selector_is_salted_by_operation_and_requires_a_current_verified_admin(): void
    {
        $fixture = $this->context();
        $contract = $this->contract($fixture);
        $wrongOperation = $this->contract($fixture);
        $wrongOperation['actor_selector'] = $contract['actor_selector'];
        $before = $this->financialSnapshot();

        $wrongSalt = $this->tick($wrongOperation);
        $fixture['admin']->forceFill(['email_verified_at' => null])->save();
        $unverified = $this->tick($contract);

        $this->assertSame('BLOCKED', $wrongSalt['outcome']);
        $this->assertSame('BLOCKED', $unverified['outcome']);
        $this->assertSame([], $fixture['google']->calls);
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertDatabaseCount('gam_revenue_corrections', 0);
    }

    #[DataProvider('staleEvidenceCases')]
    public function test_changed_original_evidence_or_financial_state_cannot_be_recaptured_and_applied(string $kind, string $checkpoint): void
    {
        $fixture = $this->context();
        $contract = $this->contract($fixture);
        $this->advanceUntil($contract, fn () => GamRevenueCorrection::query()->where('status', $checkpoint)->exists());
        $candidate = GamRevenueCorrection::query()->sole();
        $originalSnapshot = $candidate->snapshot;
        match ($kind) {
            'fact' => $fixture['facts']->first()->increment('gross_revenue_minor'),
            'rule' => $fixture['version']->update(['publisher_share_bp' => 6000, 'horus_share_bp' => 3500]),
            'period' => $fixture['period']->update(['status' => 'CLOSED']),
            'binding' => $fixture['binding']->update(['ad_unit_id' => '457']),
        };
        $before = $this->financialSnapshot();

        for ($attempt = 0; $attempt < 3; $attempt++) $result = $this->tick($contract);

        $this->assertContains($result['outcome'], ['BLOCKED', 'FAILED']);
        $this->assertSame($originalSnapshot, $candidate->fresh()->snapshot);
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertCount(1, $fixture['google']->queries);
        $this->assertDatabaseCount('gam_revenue_corrections', 1);
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 0);
    }

    public static function staleEvidenceCases(): array
    {
        return ['pending fact' => ['fact', 'PENDING'], 'pending rule' => ['rule', 'PENDING'],
            'pending period' => ['period', 'PENDING'], 'pending binding' => ['binding', 'PENDING'],
            'ready fact' => ['fact', 'READY'], 'ready rule' => ['rule', 'READY'],
            'ready period' => ['period', 'READY'], 'ready binding' => ['binding', 'READY']];
    }

    #[DataProvider('uncertainCandidateCases')]
    public function test_uncertain_starting_or_pending_reports_are_never_replaced_on_restarted_ticks(string $status, bool $expired): void
    {
        $fixture = $this->context();
        $fixture['google']->reportStatus = 'IN_PROGRESS';
        $candidate = app(GamRevenueCorrectionService::class)->start($fixture['site'], '2026-09-01', '2026-09-02', $fixture['admin']);
        $candidate->update(['status' => $status]);
        $jobId = $candidate->job['id'];
        if ($expired) $this->travel(61)->minutes();
        $contract = $this->contract($fixture);
        $before = $this->financialSnapshot();

        for ($attempt = 0; $attempt < 4; $attempt++) $this->tick($contract);

        $this->assertDatabaseCount('gam_revenue_corrections', 1);
        $this->assertSame($candidate->id, GamRevenueCorrection::query()->sole()->id);
        $this->assertSame($status, $candidate->fresh()->status);
        $this->assertSame($jobId, $candidate->fresh()->job['id']);
        $this->assertCount(1, $fixture['google']->queries);
        $this->assertNotContains('download', $fixture['google']->calls);
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 0);
    }

    public static function uncertainCandidateCases(): array
    {
        return ['starting' => ['STARTING', false], 'pending' => ['PENDING', false],
            'expired starting' => ['STARTING', true], 'expired pending' => ['PENDING', true]];
    }

    #[DataProvider('unsafeOriginalCases')]
    public function test_partitioning_never_bypasses_invalid_original_rules_or_closed_periods(string $kind): void
    {
        $fixture = $this->context(['2026-09-01', '2026-09-02', '2026-09-03']);
        $fixture['google']->missingDays = ['2026-09-02'];
        match ($kind) {
            'rule shares' => $fixture['version']->update(['publisher_share_bp' => 6000, 'horus_share_bp' => 3500]),
            'rule dates' => $fixture['version']->update(['effective_from' => '2026-09-10']),
            'period' => $fixture['period']->update(['status' => 'CLOSED']),
        };
        $contract = $this->contract($fixture);
        $before = $this->financialSnapshot();

        for ($attempt = 0; $attempt < 8; $attempt++) $result = $this->tick($contract);

        $this->assertSame('BLOCKED', $result['outcome']);
        $this->assertSame(0, $result['counts']['corrected_facts']);
        $this->assertSame(3, $result['counts']['blocked_facts']);
        $this->assertSame(0, $result['counts']['pending']);
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertDatabaseCount('gam_revenue_corrections', 1);
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 0);
        $this->assertLessThanOrEqual(1, count($fixture['google']->queries));
    }

    public static function unsafeOriginalCases(): array
    {
        return ['rule shares' => ['rule shares'], 'rule dates' => ['rule dates'], 'closed period' => ['period']];
    }

    public function test_receipt_recovery_after_interrupted_checkpoint_does_not_repeat_the_financial_transaction(): void
    {
        $fixture = $this->context();
        $contract = $this->contract($fixture);
        $this->advanceUntil($contract, fn () => GamRevenueCorrection::query()->where('status', 'PENDING')->exists());
        $candidate = app(GamRevenueCorrectionService::class)->poll(GamRevenueCorrection::query()->sole(), $fixture['admin']);
        $this->assertSame('READY', $candidate->status);
        // Capture the operation's reviewed READY checkpoint before simulating the
        // correction service committing while its caller loses the response.
        $manifest = $this->manifest($contract);
        $index = array_search($candidate->id, array_column($manifest['windows'], 'candidate_id'), true);
        $this->assertNotFalse($index);
        $manifest['windows'][$index]['candidate_digest'] = $candidate->digest;
        $manifest['windows'][$index]['state'] = 'READY';
        $manifest['windows'][$index]['review'] = app(\App\Services\Reporting\GamHistoricalOperationReview::class)
            ->review($candidate, $manifest['windows'][$index]);
        $this->assertTrue($manifest['windows'][$index]['review']['passed']);
        $this->writeManifest($contract, $manifest);
        app(GamRevenueCorrectionService::class)->apply($candidate, $candidate->digest,
            'Reviewed synthetic finite operation before interrupted checkpoint.', $fixture['admin']);
        $after = $this->financialSnapshot();
        $receipt = GamRevenueCorrectionReceipt::query()->sole()->getAttributes();
        $calls = $fixture['google']->calls;

        for ($attempt = 0; $attempt < 3; $attempt++) $result = $this->tick($contract);

        $this->assertSame(2, $result['counts']['corrected_facts']);
        $this->assertSame(0, $result['counts']['pending']);
        $this->assertSame($after, $this->financialSnapshot());
        $this->assertSame($receipt, GamRevenueCorrectionReceipt::query()->sole()->getAttributes());
        $this->assertSame($calls, $fixture['google']->calls);
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 1);
        $this->assertDatabaseCount('report_import_jobs', 2);
        $this->assertDatabaseCount('reconciliation_runs', 1);
    }

    public function test_manual_version_one_review_keeps_its_original_digest_contract_and_explicit_apply(): void
    {
        $fixture = $this->context();
        $contract = $this->contract($fixture);
        $operation = $contract['operation'];
        $fingerprint = hash('sha256', strtolower(trim($fixture['admin']->email)));
        $service = app(GamHistoricalOperation::class);
        $before = $this->financialSnapshot();
        $service->run('discover', $operation, $fingerprint);
        $service->run('prepare', $operation, $fingerprint);
        $this->travel(16)->seconds();
        $reviewed = $service->run('poll', $operation, $fingerprint);
        $manifest = $this->manifest($contract);

        $this->assertSame(1, $manifest['version']);
        $this->assertArrayNotHasKey('contract', $manifest);
        $this->assertArrayNotHasKey('batch_coverage', $manifest);
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertSame('READY', GamRevenueCorrection::query()->sole()->status);
        $batch = array_map(fn ($key) => collect($manifest['windows'])->firstWhere('key', $key), $manifest['batch']);
        foreach ($batch as &$window) unset($window['state'], $window['receipt_id']);
        unset($window);
        $versionOneDigest = GamRevenueCorrectionService::hash([
            'version' => 1, 'operation' => $operation, 'actor_id' => $fixture['admin']->id,
            'coverage' => $manifest['coverage'], 'inventory_digest' => $manifest['batch_inventory_digest'], 'batch' => $batch,
        ]);

        $this->assertSame($versionOneDigest, $reviewed['digest']);
        $this->assertSame('BLOCKED', $service->run('apply', $operation, $fingerprint)['outcome']);
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertSame('OK', $service->run('apply', $operation, $fingerprint, digest: $versionOneDigest)['outcome']);
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 1);
    }

    private function contract(array $fixture): array
    {
        $operation = bin2hex(random_bytes(32));
        $this->operationFiles[] = storage_path('app/private/gam-historical-operations/'.$operation);

        return ['schema_version' => 1, 'operation' => $operation,
            'actor_selector' => hash('sha256', $operation.':'.hash('sha256', strtolower(trim($fixture['admin']->email)))),
            'through' => '2026-10-02'];
    }

    private function tick(array $contract): array
    {
        $receiptsBefore = GamRevenueCorrectionReceipt::query()->count();
        // Resolve a new orchestrator on every tick: process-local state cannot be
        // required to recover its private checkpoint or committed receipts.
        $result = app(GamHistoricalOperation::class)->advanceOneTime($contract);
        $this->assertIsArray($result);
        $this->assertSame(1, $result['schema_version']);
        $this->assertSame($contract['operation'], $result['operation']);
        $this->assertLessThanOrEqual(1, GamRevenueCorrectionReceipt::query()->count() - $receiptsBefore);
        $public = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['synthetic-reviewer@example.test', 'news.test.example', 'other.test.example', 'gross_revenue_minor', 'publisher_earnings_minor'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $public);
        }
        $this->travel(16)->seconds();

        return $result;
    }

    private function advanceUntil(array $contract, \Closure $finished): array
    {
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $result = $this->tick($contract);
            if ($finished()) return $result;
        }
        $this->fail('The synthetic one-time operation did not reach the expected checkpoint: '.json_encode($result, JSON_THROW_ON_ERROR));
    }

    private function manifest(array $contract): array
    {
        return json_decode(file_get_contents(storage_path('app/private/gam-historical-operations/'.$contract['operation'].'.json')),
            true, flags: JSON_THROW_ON_ERROR);
    }

    private function writeManifest(array $contract, array $manifest): void
    {
        file_put_contents(storage_path('app/private/gam-historical-operations/'.$contract['operation'].'.json'),
            json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    private function candidateEvidence(GamRevenueCorrection $candidate): array
    {
        return $candidate->only(['context', 'snapshot', 'query_hash', 'job', 'proposal', 'digest'])
            + ['expires_at' => $candidate->expires_at->toIso8601String()];
    }

    private function addForwardFact(array $fixture, string $date, bool $hourly = false): DailyReport|HourlyReport
    {
        $scope = $fixture['connection']->fresh()->configuration['site_report_scope'];
        $dimension = app(ReportDimensionResolver::class)->resolve([
            'site_id' => $fixture['site']->id, 'gam_connection_id' => $fixture['binding']->gam_connection_id,
            'gam_ad_unit_id' => $fixture['binding']->ad_unit_id, 'gam_report_site' => $scope['hostname'],
            'gam_report_basis' => SiteGamReportMetrics::BASIS, 'gam_report_scope' => $scope['fingerprint'],
        ]);
        $fact = $hourly ? $this->addHourlyFact($fixture, $date) : $this->addDailyFact($fixture, $date);
        $fact->update(['report_dimension_id' => $dimension->id]);

        return $fact;
    }

    private function financialSnapshot(): array
    {
        $tables = ['sites', 'report_sources', 'report_source_connections', 'site_gam_report_bindings', 'report_import_jobs',
            'report_dimensions', 'daily_reports', 'hourly_reports', 'monthly_reports', 'financial_periods',
            'revenue_rules', 'revenue_rule_versions', 'revenue_adjustments', 'publisher_statements',
            'publisher_payments', 'publisher_payment_settlements', 'publisher_affiliate_commissions',
            'reconciliation_runs', 'gam_revenue_correction_receipts'];

        return collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    private function addDailyFact(array $fixture, string $date, ?ReportSourceConnection $connection = null): DailyReport
    {
        $fact = $fixture['facts']->first()->replicate();
        $fact->report_date = $date;
        $fact->report_source_connection_id = $connection?->id ?? $fixture['connection']->id;
        $fact->source_row_hash = hash('sha256', $fact->report_source_connection_id.':'.$date);
        $fact->save();

        return $fact;
    }

    private function addHourlyFact(array $fixture, string $date, ?ReportSourceConnection $connection = null): HourlyReport
    {
        return HourlyReport::withoutGlobalScopes()->create([
            'organization_id' => $fixture['publisher']->organization_id,
            'report_source_connection_id' => $connection?->id ?? $fixture['connection']->id,
            'report_import_job_id' => $fixture['job']->id,
            'report_dimension_id' => $fixture['facts']->first()->report_dimension_id,
            'report_date' => $date, 'report_hour' => 12, 'currency' => 'USD', 'finality' => 'FINALIZED',
            'financial_period_id' => $fixture['period']->id, 'revenue_rule_version_id' => $fixture['version']->id,
            'gross_revenue_minor' => 123, 'source_row_hash' => hash('sha256', 'synthetic-hour:'.$date.':'.($connection?->id ?? $fixture['connection']->id)),
        ]);
    }

    private function context(array $dates = ['2026-09-01', '2026-09-02']): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00 UTC'));
        Http::preventStrayRequests();
        Http::fake();
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin, ['email' => 'synthetic-reviewer@example.test']);
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser);
        $site = $this->makeSiteFor($publisher, $publisherUser, ['display_name' => 'Synthetic News', 'primary_domain' => 'news.test.example']);
        $gam = $this->makeGamConnection($admin->organization, $admin, ['network_code' => '123']);
        $source = ReportSource::query()->create(['code' => 'GAM_AD_UNIT', 'name' => 'Synthetic GAM', 'is_enabled' => true]);
        $bindingId = (string) Str::ulid();
        $connection = ReportSourceConnection::withoutGlobalScopes()->create([
            'organization_id' => $publisherUser->organization_id, 'report_source_id' => $source->id,
            'name' => 'Synthetic source', 'connection_type' => 'SITE_GAM_AD_UNIT', 'connection_id' => $bindingId,
            'currency' => 'USD', 'timezone' => 'UTC', 'status' => 'ACTIVE', 'is_enabled' => true,
            'configuration' => ['google_jobs' => ['scheduler-checkpoint' => ['id' => '999', 'status' => 'IN_PROGRESS']], 'synthetic_forward_setting' => 'retained'],
        ]);
        $binding = SiteGamReportBinding::withoutGlobalScopes()->create([
            'id' => $bindingId, 'organization_id' => $publisherUser->organization_id, 'site_id' => $site->id,
            'gam_connection_id' => $gam->id, 'report_source_connection_id' => $connection->id,
            'active_site_id' => $site->id, 'active_unit_key' => '123:456', 'network_code' => '123',
            'ad_unit_id' => '456', 'ad_unit_name' => 'Synthetic unit', 'ad_unit_code' => 'synthetic', 'starts_on' => '2026-09-01',
        ]);
        $job = ReportImportJob::withoutGlobalScopes()->create([
            'organization_id' => $publisherUser->organization_id, 'report_source_connection_id' => $connection->id,
            'import_type' => 'API', 'granularity' => 'DAILY', 'finality' => 'FINALIZED', 'status' => 'COMPLETED',
            'period_start' => '2026-09-01', 'period_end' => '2026-09-02', 'idempotency_key' => hash('sha256', 'synthetic-correction-fixture'),
        ]);
        $dimension = app(ReportDimensionResolver::class)->resolve(['site_id' => $site->id, 'gam_connection_id' => $gam->id, 'gam_ad_unit_id' => '456']);
        $period = FinancialPeriod::create(['period_key' => '2026-09', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'currency' => 'USD', 'status' => 'OPEN']);
        $rule = RevenueRule::withoutGlobalScopes()->create(['organization_id' => $publisherUser->organization_id, 'name' => 'Synthetic original rule', 'scope_type' => 'WEBSITE', 'scope_id' => $site->id, 'is_active' => true, 'effective_from' => '2026-09-01']);
        $version = RevenueRuleVersion::create(['revenue_rule_id' => $rule->id, 'version' => 1, 'publisher_share_bp' => 7000, 'horus_share_bp' => 2500, 'mcm_partner_share_bp' => 500, 'effective_from' => '2026-09-01', 'currency' => null]);
        $newVersion = RevenueRuleVersion::create(['revenue_rule_id' => $rule->id, 'version' => 2, 'publisher_share_bp' => 5000, 'horus_share_bp' => 5000, 'mcm_partner_share_bp' => 0, 'effective_from' => '2026-10-01', 'currency' => 'USD']);
        $rule->update(['current_version_id' => $newVersion->id]);
        $facts = collect($dates)->map(fn ($date) => DailyReport::withoutGlobalScopes()->create([
            'organization_id' => $publisherUser->organization_id, 'report_source_connection_id' => $connection->id,
            'report_import_job_id' => $job->id, 'report_dimension_id' => $dimension->id, 'report_date' => $date,
            'currency' => 'USD', 'finality' => 'FINALIZED', 'settlement_eligible' => true,
            'financial_period_id' => $period->id, 'revenue_rule_version_id' => $version->id,
            'gross_revenue_minor' => 123, 'demand_partner_deductions_minor' => 10, 'net_revenue_minor' => 113,
            'publisher_earnings_minor' => 79, 'horus_earnings_minor' => 29, 'mcm_partner_earnings_minor' => 5,
            'ad_requests' => 60, 'matched_requests' => 50, 'impressions' => 25, 'clicks' => 3,
            'active_view_viewable_impressions' => 21, 'active_view_measurable_impressions' => 24,
            'unfilled_impressions' => 8, 'viewability_bp' => 8750,
            'revision' => 7, 'source_row_hash' => hash('sha256', 'synthetic-row-'.$date),
        ]));
        // Install the normal forward-only scope before taking any test baseline.
        // The correction window stays entirely before this cutover.
        app(SiteGamReportScope::class)->ensure($binding);
        $connection->refresh();
        $google = new class extends GamAdUnitReportClient
        {
            public array $calls = [];
            public array $queries = [];
            public array $missingDays = [];
            public array $missingDaysByJob = [];
            public array $downloadedJobs = [];
            public ?\Closure $onStatus = null;
            public bool $failStatus = false;
            public string $reportStatus = 'COMPLETED';
            public function __construct() {}
            public function call(GamConnection $connection, string $service, string $method, array $payload = []): array
            {
                $this->calls[] = $method;
                if ($method === 'getCurrentNetwork') return ['networkCode' => '123', 'timeZone' => 'UTC', 'currencyCode' => 'AED'];
                if ($method === 'runReportJob') {
                    $this->queries[] = $payload['reportJob']['reportQuery'];
                    return ['id' => (string) count($this->queries), 'reportQuery' => ['reportCurrency' => 'USD']];
                }
                if ($method === 'getReportJobStatus') {
                    if ($this->onStatus !== null) ($this->onStatus)();
                    if ($this->failStatus) throw new \RuntimeException('Synthetic delayed Google failure');
                    return ['value' => $this->reportStatus];
                }
                throw new \RuntimeException('Unexpected synthetic Google operation: '.$method);
            }
            public function download(GamConnection $connection, string $jobId): string
            {
                $this->calls[] = 'download';
                $this->downloadedJobs[] = $jobId;
                $query = $this->queries[(int) $jobId - 1];
                $date = static fn (array $value): string => sprintf('%04d-%02d-%02d', $value['year'], $value['month'], $value['day']);
                $from = CarbonImmutable::parse($date($query['startDate']));
                $to = $date($query['endDate']);
                $missingDays = $this->missingDaysByJob[$jobId] ?? $this->missingDays;
                // Optional Active View columns may legitimately be absent in Google's CSV.
                $columns = array_keys(SiteGamReportMetrics::CORE_COLUMNS);
                $csv = implode(',', ['Dimension.DATE', 'Dimension.AD_UNIT_ID', 'Dimension.AD_UNIT_NAME', 'Dimension.SITE_NAME', ...array_map(fn ($column) => 'Column.'.$column, $columns)])."\n";
                for ($day = $from; $day->toDateString() <= $to; $day = $day->addDay()) {
                    $date = $day->toDateString();
                    if (! in_array($date, $missingDays, true)) $csv .= implode(',', [$date, '456', 'Synthetic unit', 'news.test.example', '40', '30', '20', '2', 'USD 2005000'])."\n";
                    $csv .= implode(',', [$date, '456', 'Synthetic unit', 'other.test.example', '100', '95', '90', '9', 'USD 9000000'])."\n";
                }

                return $csv;
            }
        };
        $this->app->instance(GamAdUnitReportClient::class, $google);
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);

        return compact('admin', 'publisherUser', 'publisher', 'site', 'binding', 'connection', 'job', 'period', 'version', 'newVersion', 'facts', 'google');
    }

}
