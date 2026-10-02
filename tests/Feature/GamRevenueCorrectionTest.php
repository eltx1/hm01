<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\DailyReport;
use App\Models\FinancialPeriod;
use App\Models\GamConnection;
use App\Models\GamRevenueCorrection;
use App\Models\GamRevenueCorrectionReceipt;
use App\Models\MonthlyReport;
use App\Models\Permission;
use App\Models\PublisherAffiliateCommission;
use App\Models\PublisherPayment;
use App\Models\PublisherPaymentSettlement;
use App\Models\PublisherStatement;
use App\Models\ReconciliationRun;
use App\Models\ReportImportJob;
use App\Models\ReportSource;
use App\Models\ReportSourceConnection;
use App\Models\RevenueRule;
use App\Models\RevenueRuleVersion;
use App\Models\Role;
use App\Models\SiteGamReportBinding;
use App\Services\Reporting\GamAdUnitReportClient;
use App\Services\Reporting\GamRevenueCorrectionService;
use App\Services\Reporting\ReportDimensionResolver;
use App\Services\Reporting\SiteGamReportMetrics;
use App\Services\Reporting\SiteGamReportScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

final class GamRevenueCorrectionTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_preparing_and_polling_collect_evidence_without_mutating_financial_or_forward_import_state(): void
    {
        $fixture = $this->context();
        $before = $this->financialSnapshot();
        $candidate = $this->start($fixture);

        $this->assertSame('PENDING', $candidate->status);
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertSame($candidate->id, $this->start($fixture)->id);
        $this->assertCount(1, $fixture['google']->queries);
        $query = $fixture['google']->queries[0];
        $this->assertSame(['DATE', 'AD_UNIT_ID', 'SITE_NAME'], $query['dimensions']);
        $this->assertSame(array_keys(SiteGamReportMetrics::COLUMNS), $query['columns']);
        $this->assertSame('WHERE AD_UNIT_ID = :unit', $query['statement']['query']);
        $this->assertSame('456', $query['statement']['values'][0]['value']['value']);
        $this->assertSame('USD', $query['reportCurrency']);
        $this->assertSame('PUBLISHER', $query['timeZoneType']);
        $this->assertSame('FLAT', $query['adUnitView']);
        $this->assertStringNotContainsString('TOTAL_LINE_ITEM', json_encode($query));

        $this->travel(16)->seconds();
        $candidate = app(GamRevenueCorrectionService::class)->poll($candidate, $fixture['admin']);
        $this->assertSame('READY', $candidate->status);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $candidate->digest);
        $this->assertSame(['2026-09-01', '2026-09-02'], array_keys($candidate->job['result']['days']));
        $this->assertSame(2, $candidate->job['result']['excluded_site_rows']);
        $this->assertSame(201, $candidate->job['result']['days']['2026-09-01']['gross_revenue_minor']);
        $this->assertSame($before, $this->financialSnapshot());

        $calls = $fixture['google']->calls;
        app(GamRevenueCorrectionService::class)->poll($candidate->fresh(), $fixture['admin']);
        $this->assertSame($calls, $fixture['google']->calls);
        $this->assertSame($before, $this->financialSnapshot());
        Http::assertNothingSent();
    }

    public function test_apply_revises_the_same_facts_using_the_original_rule_and_server_side_google_metrics(): void
    {
        $fixture = $this->context();
        $candidate = $this->ready($fixture);
        $beforeFacts = $fixture['facts']->map(fn ($fact) => $fact->getAttributes())->all();
        $sourceBefore = $fixture['connection']->fresh()->getAttributes();
        $forwardJobBefore = $fixture['job']->fresh()->getAttributes();
        $oldDimension = $fixture['facts']->first()->dimension->getAttributes();

        $this->post(route('admin.reporting.gam-corrections.apply', $candidate->id), [
            'digest' => $candidate->digest,
            'confirm_review' => '1',
            'reason' => 'Reviewed synthetic exact-site Ad Exchange evidence.',
            // Browser-supplied financial values and attribution must have no authority.
            'gross_revenue_minor' => 999999,
            'publisher_earnings_minor' => 999999,
            'revenue_rule_version_id' => $fixture['newVersion']->id,
            'site_id' => (string) Str::ulid(),
            'proposal' => ['gross_revenue_minor' => 999999],
        ])->assertRedirect(route('admin.reporting.gam-corrections.show', $candidate->id));

        $candidate->refresh();
        $this->assertSame('APPLIED', $candidate->status);
        $this->assertSame(2, DailyReport::withoutGlobalScopes()->count());
        foreach ($fixture['facts'] as $original) {
            $fact = $original->fresh();
            $this->assertSame($original->id, $fact->id);
            $this->assertSame(8, (int) $fact->revision);
            $this->assertSame('FINALIZED', $fact->finality->value);
            $this->assertTrue($fact->settlement_eligible);
            $this->assertSame($fixture['version']->id, $fact->revenue_rule_version_id);
            $this->assertSame($fixture['period']->id, $fact->financial_period_id);
            $this->assertSame($fixture['connection']->id, $fact->report_source_connection_id);
            $this->assertSame('USD', $fact->currency);
            foreach (['gross_revenue_minor' => 201, 'net_revenue_minor' => 191,
                'demand_partner_deductions_minor' => 10, 'invalid_traffic_adjustments_minor' => 0,
                'other_adjustments_minor' => 0, 'publisher_earnings_minor' => 133,
                'horus_earnings_minor' => 49, 'mcm_partner_earnings_minor' => 9,
                'ad_requests' => 40, 'matched_requests' => 30, 'impressions' => 20, 'clicks' => 2] as $key => $value) {
                $this->assertSame($value, (int) $fact->{$key}, $key);
            }
            foreach (['active_view_viewable_impressions', 'active_view_measurable_impressions', 'unfilled_impressions', 'viewability_bp'] as $key) {
                $this->assertNull($fact->{$key}, $key);
            }
            $this->assertSame(SiteGamReportMetrics::BASIS, $fact->dimension->external_dimensions['gam_report_basis']);
            $this->assertSame('news.test.example', $fact->dimension->external_dimensions['gam_report_site']);
            $this->assertNotSame($original->source_row_hash, $fact->source_row_hash);
        }

        $receipt = GamRevenueCorrectionReceipt::query()->where('correction_id', $candidate->id)->sole();
        $this->assertSame($candidate->digest, $receipt->digest);
        $this->assertSame($candidate->query_hash, $receipt->query_hash);
        $this->assertSame($fixture['admin']->id, $receipt->approved_by);
        $this->assertSame('Reviewed synthetic exact-site Ad Exchange evidence.', $receipt->reason);
        $this->assertCount(2, $receipt->before['facts']);
        $this->assertCount(2, $receipt->after['facts']);
        $this->assertNotSame($receipt->before_hash, $receipt->after_hash);
        $this->assertNotNull($receipt->applied_at);
        $this->assertNotNull(ReportImportJob::withoutGlobalScopes()->find($receipt->report_import_job_id));
        $this->assertNotNull(ReconciliationRun::withoutGlobalScopes()->find($receipt->reconciliation_run_id));
        $this->assertSame([$receipt->report_import_job_id], DailyReport::withoutGlobalScopes()->pluck('report_import_job_id')->unique()->values()->all());
        $this->assertSame(array_column($beforeFacts, 'id'), collect($receipt->before['facts'])->pluck('fact.id')->all());
        $this->assertSame($sourceBefore, $fixture['connection']->fresh()->getAttributes());
        $this->assertSame($forwardJobBefore, $fixture['job']->fresh()->getAttributes());
        $this->assertSame($oldDimension, $fixture['facts']->first()->dimension()->findOrFail($oldDimension['id'])->getAttributes());
        $this->assertDatabaseCount('monthly_reports', 0);
        $this->assertDatabaseCount('publisher_statements', 0);
        $this->assertDatabaseCount('publisher_payments', 0);
        $appliedView = $this->get(route('admin.reporting.gam-corrections.show', $candidate->id))->assertOk()->assertSee('Applied correction receipt');
        $this->assertStringContainsString('no-store', $appliedView->headers->get('Cache-Control'));
        $this->fixture('reports-gam-correction-applied', $appliedView);
        Http::assertNothingSent();
    }

    public function test_an_estimated_original_fact_can_be_finalized_after_the_google_day_is_complete(): void
    {
        $fixture = $this->context();
        DailyReport::withoutGlobalScopes()->update(['finality' => 'ESTIMATED', 'settlement_eligible' => false]);
        $candidate = $this->ready($fixture);
        $readyView = $this->get(route('admin.reporting.gam-corrections.show', $candidate->id))->assertOk()->assertSee('Apply reviewed correction')->assertSee('ESTIMATED');
        $this->assertStringContainsString('no-store', $readyView->headers->get('Cache-Control'));
        $this->fixture('reports-gam-correction-ready', $readyView);

        $applied = app(GamRevenueCorrectionService::class)->apply($candidate, $candidate->digest, 'Reviewed completed Google days and original allocation.', $fixture['admin']);

        $this->assertSame('APPLIED', $applied->status);
        $this->assertSame(['FINALIZED'], DB::table('daily_reports')->pluck('finality')->unique()->all());
        $this->assertSame(2, DailyReport::withoutGlobalScopes()->where('settlement_eligible', true)->count());
    }

    public function test_repeated_apply_returns_the_original_receipt_without_another_import_or_revision(): void
    {
        $fixture = $this->context();
        $candidate = $this->ready($fixture);
        $service = app(GamRevenueCorrectionService::class);
        $applied = $service->apply($candidate, $candidate->digest, 'Reviewed synthetic evidence for this correction.', $fixture['admin']);
        $before = $this->financialSnapshot();
        $receipt = $applied->receipt;

        $repeated = $service->apply($candidate->fresh(), $candidate->digest, 'A repeated request must not overwrite the original approval.', $fixture['admin']);

        $this->assertSame('APPLIED', $repeated->status);
        $this->assertSame($receipt, $repeated->receipt);
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 1);
        $this->assertDatabaseCount('report_import_jobs', 2);
        $this->assertDatabaseCount('reconciliation_runs', 1);
        $receiptModel = GamRevenueCorrectionReceipt::query()->where('correction_id', $candidate->id)->sole();
        foreach ([fn () => $receiptModel->update(['reason' => 'Attempted replacement of the original approval.']), fn () => $receiptModel->fresh()->delete()] as $mutation) {
            try {
                $mutation();
                $this->fail('Applied receipts must remain immutable.');
            } catch (\LogicException $exception) {
                $this->assertSame('Correction receipts are immutable.', $exception->getMessage());
            }
        }
        $this->assertSame($before, $this->financialSnapshot());
    }

    public function test_a_missing_exact_site_day_blocks_the_whole_candidate_without_fabricating_zero(): void
    {
        $fixture = $this->context();
        $fixture['google']->missingDays = ['2026-09-02'];
        $before = $this->financialSnapshot();
        $candidate = $this->start($fixture);
        $this->travel(16)->seconds();
        $candidate = app(GamRevenueCorrectionService::class)->poll($candidate, $fixture['admin']);

        $this->assertSame('BLOCKED', $candidate->status);
        $this->assertArrayNotHasKey('2026-09-02', $candidate->job['result']['days']);
        $this->assertSame(201, $candidate->job['result']['days']['2026-09-01']['gross_revenue_minor']);
        $this->assertNull($candidate->proposal['totals']);
        $this->assertNull($candidate->proposal['days'][1]['fresh']);
        $this->assertNull($candidate->proposal['days'][1]['projected']);
        $blockedView = $this->get(route('admin.reporting.gam-corrections.show', $candidate->id))->assertOk()->assertSee('Entire candidate blocked')->assertSee('Missing exact-site row; not zero');
        $this->fixture('reports-gam-correction-blocked', $blockedView);
        $this->assertValidationFailure(fn () => app(GamRevenueCorrectionService::class)->apply($candidate, $candidate->digest ?? str_repeat('0', 64), 'Reviewed candidate still missing one exact-site day.', $fixture['admin']));
        $this->assertSame($before, $this->financialSnapshot());
    }

    #[DataProvider('staleEvidenceCases')]
    public function test_changes_after_review_block_apply_and_preserve_the_changed_financial_state(string $kind): void
    {
        $fixture = $this->context();
        $candidate = $this->ready($fixture);
        match ($kind) {
            'fact' => $fixture['facts']->first()->increment('gross_revenue_minor'),
            'rule' => $fixture['version']->update(['publisher_share_bp' => 6000, 'horus_share_bp' => 3500]),
            'configuration' => $fixture['connection']->update(['configuration' => array_replace($fixture['connection']->configuration, ['site_report_scope' => ['hostname' => 'changed.test.example']])]),
            'period' => $fixture['period']->update(['status' => 'CLOSED']),
            'binding' => $fixture['binding']->update(['ad_unit_id' => '457']),
        };
        $before = $this->financialSnapshot();

        $this->assertValidationFailure(fn () => app(GamRevenueCorrectionService::class)->apply($candidate, $candidate->digest, 'Reviewed before source evidence changed.', $fixture['admin']));

        $this->assertSame($before, $this->financialSnapshot());
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 0);
    }

    public static function staleEvidenceCases(): array
    {
        return array_combine(['fact', 'rule', 'configuration', 'period', 'binding'], array_map(fn ($value) => [$value], ['fact', 'rule', 'configuration', 'period', 'binding']));
    }

    #[DataProvider('settlementEvidenceCases')]
    public function test_any_settlement_evidence_created_after_review_blocks_apply(string $kind): void
    {
        $fixture = $this->context();
        $candidate = $this->ready($fixture);
        $this->addSettlementEvidence($fixture, $kind);
        $before = $this->financialSnapshot();

        $this->assertValidationFailure(fn () => app(GamRevenueCorrectionService::class)->apply($candidate, $candidate->digest, 'Reviewed before settlement evidence appeared.', $fixture['admin']));

        $this->assertSame($before, $this->financialSnapshot());
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 0);
    }

    public static function settlementEvidenceCases(): array
    {
        return ['draft statement' => ['statement'], 'monthly rollup' => ['monthly'], 'affiliate earnings' => ['affiliate'], 'payout' => ['payment'], 'payout settlement' => ['settlement']];
    }

    #[DataProvider('settlementEvidenceCases')]
    public function test_existing_settlement_evidence_blocks_before_submitting_a_google_report(string $kind): void
    {
        $fixture = $this->context();
        $this->addSettlementEvidence($fixture, $kind);
        $before = $this->financialSnapshot();

        $candidate = $this->start($fixture);

        $this->assertSame('BLOCKED', $candidate->status);
        $flag = match ($kind) {
            'statement' => 'STATEMENTS_BLOCK_CORRECTION',
            'monthly' => 'MONTHLY_BLOCK_CORRECTION',
            'affiliate' => 'AFFILIATES_BLOCK_CORRECTION',
            'payment' => 'PAYMENTS_BLOCK_CORRECTION',
            'settlement' => 'SETTLEMENTS_BLOCK_CORRECTION',
        };
        $this->assertContains($flag, $candidate->proposal['flags']);
        $this->assertCount(0, $fixture['google']->queries);
        $this->assertSame($before, $this->financialSnapshot());
    }

    public function test_unverified_existing_video_metrics_block_instead_of_being_discarded(): void
    {
        $fixture = $this->context();
        $fixture['facts']->first()->update(['video_starts' => 5, 'completed_views' => 3]);
        $before = $this->financialSnapshot();
        $candidate = $this->start($fixture);
        $this->travel(16)->seconds();
        $candidate = app(GamRevenueCorrectionService::class)->poll($candidate, $fixture['admin']);

        $this->assertSame('BLOCKED', $candidate->status);
        $this->assertContains('UNVERIFIED_STORED_VIDEO_METRICS', $candidate->proposal['flags']);
        $this->assertSame($before, $this->financialSnapshot());
    }

    #[DataProvider('outOfOrderPollCases')]
    public function test_an_old_google_poll_cannot_replace_newer_durable_job_or_terminal_evidence(string $newStatus, bool $oldPollFails): void
    {
        $fixture = $this->context();
        $candidate = $this->start($fixture);
        $before = $this->financialSnapshot();
        $expected = null;
        $fixture['google']->onStatus = function () use ($candidate, $newStatus, &$expected): void {
            // Simulate another request committing while this request waits on
            // Google, after a process-local or expiring cache lock is lost.
            $newer = $candidate->fresh();
            $job = $newer->job;
            $job['polls']++;
            $job['next_poll_at'] += 20;
            $attributes = ['status' => $newStatus, 'job' => $job];
            if ($newStatus !== 'PENDING') {
                $attributes['job']['status'] = 'COMPLETED';
                $attributes['proposal'] = ['eligible' => true, 'synthetic_concurrent_evidence' => 'retained'];
                $attributes['digest'] = hash('sha256', 'synthetic-concurrent-review');
                if ($newStatus === 'APPLIED') {
                    $attributes['applied_at'] = now();
                    $attributes['receipt'] = ['synthetic_concurrent_receipt' => 'retained'];
                }
            }
            $newer->update($attributes);
            $expected = $newer->fresh()->getAttributes();
        };
        $fixture['google']->failStatus = $oldPollFails;
        $this->travel(16)->seconds();

        $result = app(GamRevenueCorrectionService::class)->poll($candidate, $fixture['admin']);

        $this->assertSame($expected, $result->getAttributes());
        $this->assertSame($expected, $candidate->fresh()->getAttributes());
        $this->assertSame($before, $this->financialSnapshot());
    }

    public static function outOfOrderPollCases(): array
    {
        return ['new pending job, old success' => ['PENDING', false], 'new pending job, old failure' => ['PENDING', true],
            'ready, old success' => ['READY', false], 'ready, old failure' => ['READY', true],
            'applied, old success' => ['APPLIED', false], 'applied, old failure' => ['APPLIED', true]];
    }

    public function test_tampered_digest_and_expired_review_cannot_write(): void
    {
        $fixture = $this->context();
        $candidate = $this->ready($fixture);
        $before = $this->financialSnapshot();
        $service = app(GamRevenueCorrectionService::class);

        $this->assertValidationFailure(fn () => $service->apply($candidate, str_repeat('0', 64), 'Reviewed with a forged digest must be rejected.', $fixture['admin']));
        $this->assertSame($before, $this->financialSnapshot());
        $this->travelTo($candidate->expires_at->addSecond());
        $this->assertValidationFailure(fn () => $service->apply($candidate->fresh(), $candidate->digest, 'Reviewed evidence has now expired.', $fixture['admin']));
        $this->assertSame($before, $this->financialSnapshot());
    }

    public function test_explicit_replacement_preserves_old_evidence_and_submits_only_one_new_google_job(): void
    {
        $fixture = $this->context();
        $candidate = $this->ready($fixture);
        $evidence = $candidate->only(['context', 'snapshot', 'query_hash', 'job', 'proposal', 'digest', 'receipt', 'expires_at']);
        $before = $this->financialSnapshot();

        $response = $this->post(route('admin.reporting.gam-corrections.replace', $candidate->id))->assertRedirect();
        $replacement = GamRevenueCorrection::query()->where('status', 'PENDING')->sole();
        $response->assertRedirect(route('admin.reporting.gam-corrections.show', $replacement->id));
        $this->assertNotSame($candidate->id, $replacement->id);
        $this->assertSame('SUPERSEDED', $candidate->fresh()->status);
        $this->assertEquals($evidence, $candidate->fresh()->only(array_keys($evidence)));

        $this->post(route('admin.reporting.gam-corrections.replace', $candidate->id))->assertRedirect($response->headers->get('Location'));
        $this->assertSame($replacement->id, $this->start($fixture)->id);
        $this->assertCount(2, $fixture['google']->queries);
        $this->assertDatabaseCount('gam_revenue_corrections', 2);
        $this->assertValidationFailure(fn () => app(GamRevenueCorrectionService::class)->apply($candidate->fresh(), $candidate->digest, 'The superseded digest cannot approve a correction.', $fixture['admin']));
        $this->get(route('admin.reporting.gam-corrections.show', $candidate->id))->assertOk()->assertDontSee('action="'.route('admin.reporting.gam-corrections.apply', $candidate->id).'"', false);
        $this->assertSame($before, $this->financialSnapshot());
    }

    public function test_pending_and_applied_candidates_cannot_be_replaced(): void
    {
        $fixture = $this->context();
        $candidate = $this->start($fixture);
        $this->postJson(route('admin.reporting.gam-corrections.replace', $candidate->id))->assertUnprocessable();
        $this->assertSame('PENDING', $candidate->fresh()->status);
        $this->assertCount(1, $fixture['google']->queries);

        $this->travel(16)->seconds();
        $service = app(GamRevenueCorrectionService::class);
        $candidate = $service->poll($candidate, $fixture['admin']);
        $candidate = $service->apply($candidate, $candidate->digest, 'Reviewed synthetic evidence before testing applied retention.', $fixture['admin']);
        $before = $this->financialSnapshot();
        $this->postJson(route('admin.reporting.gam-corrections.replace', $candidate->id))->assertUnprocessable();
        $this->assertSame('APPLIED', $candidate->fresh()->status);
        $this->assertCount(1, $fixture['google']->queries);
        $this->assertSame($before, $this->financialSnapshot());
    }

    public function test_receipt_failure_rolls_back_facts_import_reconciliation_and_candidate_status(): void
    {
        $fixture = $this->context();
        $candidate = $this->ready($fixture);
        $before = $this->financialSnapshot();
        $event = 'eloquent.creating: '.GamRevenueCorrectionReceipt::class;
        Event::listen($event, fn () => throw new \RuntimeException('Synthetic receipt persistence failure'));
        try {
            app(GamRevenueCorrectionService::class)->apply($candidate, $candidate->digest, 'Reviewed synthetic rollback coverage.', $fixture['admin']);
            $this->fail('A receipt persistence failure must abort the correction transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic receipt persistence failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $this->assertSame($before, $this->financialSnapshot());
        $this->assertSame('READY', $candidate->fresh()->status);
        $this->assertNull($candidate->fresh()->receipt);
    }

    public function test_all_routes_require_a_horus_admin_with_all_three_permissions_and_the_owning_actor(): void
    {
        $fixture = $this->context();
        $candidate = $this->ready($fixture);
        $support = $this->makeUser($fixture['admin']->organization, RoleName::SupportAgent);
        $adOps = $this->makeUser($fixture['admin']->organization, RoleName::AdOpsAdmin);
        foreach ([$fixture['publisherUser'], $support, $adOps] as $unauthorized) {
            $this->actingAs($unauthorized);
            $this->get(route('admin.reporting.gam-corrections'))->assertForbidden();
            $this->get(route('admin.reporting.gam-corrections.show', $candidate->id))->assertForbidden();
            $this->post(route('admin.reporting.gam-corrections.start'), $this->startPayload($fixture))->assertForbidden();
            $this->post(route('admin.reporting.gam-corrections.poll', $candidate->id))->assertForbidden();
            $this->post(route('admin.reporting.gam-corrections.apply', $candidate->id), $this->applyPayload($candidate))->assertForbidden();
            $this->post(route('admin.reporting.gam-corrections.replace', $candidate->id))->assertForbidden();
        }

        // A publisher holding those permissions still fails the Horus-only boundary.
        $role = Role::create(['name' => 'SYNTHETIC_REPORT_REVIEWER', 'display_name' => 'Synthetic reviewer', 'is_system' => false]);
        $role->permissions()->sync(Permission::whereIn('name', ['reporting.admin.view', 'reporting.import', 'finance.adjustments.approve'])->pluck('id'));
        $fixture['publisherUser']->roles()->attach($role);
        $this->actingAs($fixture['publisherUser']->fresh())->post(route('admin.reporting.gam-corrections.apply', $candidate->id), $this->applyPayload($candidate))->assertForbidden();

        $otherAdmin = $this->makeUser($fixture['admin']->organization, RoleName::SuperAdmin);
        $this->actingAs($otherAdmin);
        $this->get(route('admin.reporting.gam-corrections.show', $candidate->id))->assertNotFound();
        $this->post(route('admin.reporting.gam-corrections.poll', $candidate->id))->assertNotFound();
        $this->post(route('admin.reporting.gam-corrections.apply', $candidate->id), $this->applyPayload($candidate))->assertNotFound();
        $this->post(route('admin.reporting.gam-corrections.replace', $candidate->id))->assertNotFound();
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 0);
        $this->assertDatabaseCount('report_import_jobs', 1);
    }

    public function test_review_confirmation_reason_and_digest_are_required_before_apply(): void
    {
        $fixture = $this->context();
        $candidate = $this->ready($fixture);
        $before = $this->financialSnapshot();
        foreach (['confirm_review', 'reason', 'digest'] as $required) {
            $payload = $this->applyPayload($candidate);
            unset($payload[$required]);
            $this->postJson(route('admin.reporting.gam-corrections.apply', $candidate->id), $payload)->assertUnprocessable()->assertJsonValidationErrors($required);
        }
        $this->assertSame($before, $this->financialSnapshot());
    }

    public function test_each_reviewer_permission_is_individually_required(): void
    {
        $fixture = $this->context();
        $candidate = $this->ready($fixture);
        $permissions = ['reporting.admin.view', 'reporting.import', 'finance.adjustments.approve'];
        foreach ($permissions as $missing) {
            $reviewer = $this->makeUser($fixture['admin']->organization, RoleName::SupportAgent);
            $role = Role::create(['name' => 'SYNTHETIC_'.strtoupper(str_replace('.', '_', $missing)), 'display_name' => 'Synthetic restricted reviewer', 'is_system' => false]);
            $role->permissions()->sync(Permission::whereIn('name', array_diff($permissions, [$missing]))->pluck('id'));
            $reviewer->roles()->sync([$role->id]);
            $this->actingAs($reviewer->fresh());

            $this->get(route('admin.reporting.gam-corrections'))->assertForbidden();
            $this->post(route('admin.reporting.gam-corrections.start'), $this->startPayload($fixture))->assertForbidden();
            $this->post(route('admin.reporting.gam-corrections.apply', $candidate->id), $this->applyPayload($candidate))->assertForbidden();
        }
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 0);
    }

    public function test_invalid_or_cross_month_ranges_fail_before_google_report_submission(): void
    {
        $fixture = $this->context();
        foreach ([['from' => '2026-09-30', 'to' => '2026-10-01'], ['from' => '2026-10-10', 'to' => '2026-10-10'],
            ['from' => '2026-08-31', 'to' => '2026-09-01'], ['from' => 'not-a-date'], ['to' => '2026-08-31']] as $changes) {
            $this->postJson(route('admin.reporting.gam-corrections.start'), array_replace($this->startPayload($fixture), $changes))->assertUnprocessable();
        }
        $this->assertCount(0, $fixture['google']->queries);
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 0);
    }

    private function startPayload(array $fixture): array
    {
        return ['site_id' => $fixture['site']->id, 'from' => '2026-09-01', 'to' => '2026-09-02'];
    }

    private function applyPayload(GamRevenueCorrection $candidate): array
    {
        return ['digest' => $candidate->digest, 'confirm_review' => '1', 'reason' => 'Reviewed synthetic exact-site correction evidence.'];
    }

    private function start(array $fixture): GamRevenueCorrection
    {
        return app(GamRevenueCorrectionService::class)->start($fixture['site'], '2026-09-01', '2026-09-02', $fixture['admin']);
    }

    private function ready(array $fixture): GamRevenueCorrection
    {
        $candidate = $this->start($fixture);
        $this->travel(16)->seconds();
        $candidate = app(GamRevenueCorrectionService::class)->poll($candidate, $fixture['admin']);
        $this->assertSame('READY', $candidate->status, json_encode($candidate->proposal));

        return $candidate;
    }

    private function assertValidationFailure(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Unverified historical correction must fail validation.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    private function financialSnapshot(): array
    {
        $tables = ['sites', 'report_source_connections', 'site_gam_report_bindings', 'report_import_jobs',
            'report_dimensions', 'daily_reports', 'hourly_reports', 'monthly_reports', 'financial_periods',
            'revenue_rules', 'revenue_rule_versions', 'revenue_adjustments', 'publisher_statements',
            'publisher_payments', 'publisher_payment_settlements', 'publisher_affiliate_commissions',
            'reconciliation_runs', 'gam_revenue_correction_receipts'];

        return collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    private function context(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00 UTC'));
        Http::preventStrayRequests();
        Http::fake();
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
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
        $facts = collect(['2026-09-01', '2026-09-02'])->map(fn ($date) => DailyReport::withoutGlobalScopes()->create([
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
            public ?\Closure $onStatus = null;
            public bool $failStatus = false;
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
                    return ['value' => 'COMPLETED'];
                }
                throw new \RuntimeException('Unexpected synthetic Google operation: '.$method);
            }
            public function download(GamConnection $connection, string $jobId): string
            {
                // Optional Active View columns may legitimately be absent in Google's CSV.
                $columns = array_keys(SiteGamReportMetrics::CORE_COLUMNS);
                $csv = implode(',', ['Dimension.DATE', 'Dimension.AD_UNIT_ID', 'Dimension.AD_UNIT_NAME', 'Dimension.SITE_NAME', ...array_map(fn ($column) => 'Column.'.$column, $columns)])."\n";
                foreach (['2026-09-01', '2026-09-02'] as $date) {
                    if (! in_array($date, $this->missingDays, true)) $csv .= implode(',', [$date, '456', 'Synthetic unit', 'news.test.example', '40', '30', '20', '2', 'USD 2005000'])."\n";
                    $csv .= implode(',', [$date, '456', 'Synthetic unit', 'other.test.example', '100', '95', '90', '9', 'USD 9000000'])."\n";
                }

                return $csv;
            }
        };
        $this->app->instance(GamAdUnitReportClient::class, $google);
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);

        return compact('admin', 'publisherUser', 'publisher', 'site', 'binding', 'connection', 'job', 'period', 'version', 'newVersion', 'facts', 'google');
    }

    private function addSettlementEvidence(array $fixture, string $kind): void
    {
        if ($kind === 'monthly') {
            MonthlyReport::withoutGlobalScopes()->create([
                'organization_id' => $fixture['publisher']->organization_id,
                'financial_period_id' => $fixture['period']->id,
                'report_source_connection_id' => $fixture['connection']->id,
                'report_dimension_id' => $fixture['facts']->first()->report_dimension_id,
                'revenue_rule_version_id' => $fixture['version']->id,
                'period_key' => '2026-09', 'currency' => 'USD', 'snapshot_hash' => hash('sha256', 'synthetic-monthly'),
            ]);

            return;
        }

        $statementPublisher = $fixture['publisher'];
        if ($kind === 'affiliate') {
            $otherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
            $statementPublisher = $this->makePublisherFor($otherUser);
        }
        $statement = PublisherStatement::withoutGlobalScopes()->create([
            'organization_id' => $statementPublisher->organization_id,
            'publisher_id' => $statementPublisher->id, 'financial_period_id' => $fixture['period']->id,
            'statement_number' => 'SYNTHETIC-CORRECTION-'.$kind, 'status' => 'DRAFT', 'currency' => 'USD',
            'line_items' => [], 'snapshot' => [], 'snapshot_hash' => hash('sha256', 'synthetic-statement-'.$kind),
        ]);
        if ($kind === 'affiliate') {
            PublisherAffiliateCommission::create([
                'referrer_publisher_id' => $fixture['publisher']->id, 'referred_publisher_id' => $statementPublisher->id,
                'source_publisher_statement_id' => $statement->id, 'financial_period_id' => $fixture['period']->id,
                'currency' => 'USD', 'basis_minor' => 100, 'commission_rate_bp' => 500, 'commission_minor' => 5, 'status' => 'EARNED',
            ]);

            return;
        }
        if ($kind === 'statement') return;
        $payment = PublisherPayment::withoutGlobalScopes()->create([
            'organization_id' => $fixture['publisher']->organization_id,
            'publisher_id' => $fixture['publisher']->id, 'publisher_statement_id' => $statement->id,
            'payment_number' => 'SYNTHETIC-CORRECTION-'.$kind, 'status' => 'PENDING',
            'currency' => 'USD', 'amount_minor' => 50,
        ]);
        if ($kind === 'settlement') {
            PublisherPaymentSettlement::withoutGlobalScopes()->create([
                'organization_id' => $fixture['publisher']->organization_id,
                'publisher_id' => $fixture['publisher']->id, 'publisher_payment_id' => $payment->id,
                'settlement_reference' => 'SYNTHETIC-CORRECTION-SETTLEMENT', 'currency' => 'USD',
                'amount_minor' => 50, 'settled_on' => now(), 'recorded_by' => $fixture['admin']->id,
            ]);
        }
    }

    private function fixture(string $name, \Illuminate\Testing\TestResponse $response): void
    {
        if (getenv('HORUS_UI_FIXTURES') !== '1') return;
        $directory = storage_path('framework/testing/form-experience');
        if (! is_dir($directory)) mkdir($directory, 0755, true);
        $html = str_replace('</head>', '<link rel="stylesheet" href="/fixture.css"></head>', $response->getContent());
        $html = str_replace('</body>', '<script type="module" src="/fixture.js"></script></body>', $html);
        file_put_contents($directory.'/'.$name.'.html', $html);
    }
}
