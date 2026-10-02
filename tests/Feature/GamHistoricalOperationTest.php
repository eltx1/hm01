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
use App\Models\Permission;
use App\Models\ReportImportJob;
use App\Models\ReportSource;
use App\Models\ReportSourceConnection;
use App\Models\RevenueRule;
use App\Models\RevenueRuleVersion;
use App\Models\Role;
use App\Models\SiteGamReportBinding;
use App\Services\Reporting\GamAdUnitReportClient;
use App\Services\Reporting\GamHistoricalOperation;
use App\Services\Reporting\GamRevenueCorrectionService;
use App\Services\Reporting\ReportDimensionResolver;
use App\Services\Reporting\SiteGamReportMetrics;
use App\Services\Reporting\SiteGamReportScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

final class GamHistoricalOperationTest extends TestCase
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

    public function test_discovery_counts_all_gam_source_codes_and_types_including_inactive_and_unbound_facts(): void
    {
        $fixture = $this->context();
        foreach ([['HORUS_GAM', 'LEGACY', false], ['MCM_PARTNER_GAM', 'LEGACY', true],
            ['PUBLISHER_GAM', 'LEGACY', false], ['GAM_AD_UNIT', 'LEGACY', true],
            ['CUSTOM_CSV', 'GAM_CONNECTION', false]] as [$code, $type, $enabled]) {
            $source = ReportSource::query()->firstOrCreate(['code' => $code], ['name' => 'Synthetic '.$code, 'is_enabled' => $enabled]);
            $connection = ReportSourceConnection::withoutGlobalScopes()->create([
                'organization_id' => $fixture['publisher']->organization_id, 'report_source_id' => $source->id,
                'name' => 'Synthetic unbound source', 'connection_type' => $type, 'connection_id' => (string) Str::ulid(),
                'currency' => 'USD', 'timezone' => 'UTC', 'status' => $enabled ? 'ACTIVE' : 'DISABLED', 'is_enabled' => $enabled,
            ]);
            $this->addDailyFact($fixture, '2026-09-07', $connection);
            $this->addHourlyFact($fixture, '2026-09-07', $connection);
        }
        $this->addHourlyFact($fixture, '2026-09-01');
        $this->addForwardFact($fixture, '2026-10-10');
        $this->addForwardFact($fixture, '2026-10-10', hourly: true);
        // A truly non-GAM source and dimension must not inflate the GAM census.
        $source = ReportSource::query()->where('code', 'CUSTOM_CSV')->sole();
        $other = ReportSourceConnection::withoutGlobalScopes()->create([
            'organization_id' => $fixture['publisher']->organization_id, 'report_source_id' => $source->id,
            'name' => 'Synthetic direct source', 'connection_type' => 'CSV', 'connection_id' => (string) Str::ulid(),
            'currency' => 'USD', 'timezone' => 'UTC', 'status' => 'ACTIVE', 'is_enabled' => true,
        ]);
        $dimension = app(ReportDimensionResolver::class)->resolve(['site_id' => $fixture['site']->id]);
        $this->addDailyFact($fixture, '2026-09-09', $other)->update(['report_dimension_id' => $dimension->id]);
        $this->addHourlyFact($fixture, '2026-09-09', $other)->update(['report_dimension_id' => $dimension->id]);
        $before = $this->financialSnapshot();
        $operation = $this->operation();

        $result = $this->runOperation('discover', $operation, $fixture);

        $this->assertPublicResult($result, $operation, $fixture);
        $this->assertSame(6, $result['counts']['sources']);
        $this->assertSame(8, $result['counts']['daily_facts']);
        $this->assertSame(7, $result['counts']['hourly_facts']);
        $this->assertSame(11, $result['counts']['blocked_facts']);
        $this->assertSame(2, $result['counts']['forward_facts']);
        $this->assertSame(1, $result['counts']['windows']);
        $this->assertSame(10, ((array) $result['reasons'])['UNBOUND_SOURCE']);
        $this->assertSame(1, ((array) $result['reasons'])['HOURLY_FACTS']);
        $this->assertSame([], $fixture['google']->calls);
        $this->assertSame($before, $this->financialSnapshot());
    }

    public function test_dimension_identified_gam_sources_include_their_earlier_unattributed_facts(): void
    {
        $fixture = $this->context();
        $source = ReportSource::query()->create(['code' => 'CUSTOM_CSV', 'name' => 'Synthetic legacy import', 'is_enabled' => false]);
        $connection = ReportSourceConnection::withoutGlobalScopes()->create([
            'organization_id' => $fixture['publisher']->organization_id, 'report_source_id' => $source->id,
            'name' => 'Synthetic source discovered from later dimensions', 'connection_type' => 'CSV',
            'connection_id' => (string) Str::ulid(), 'currency' => 'USD', 'timezone' => 'UTC',
            'status' => 'DISABLED', 'is_enabled' => false,
        ]);
        $plainDimension = app(ReportDimensionResolver::class)->resolve(['site_id' => $fixture['site']->id]);
        $earlier = $this->addDailyFact($fixture, '2026-09-05', $connection);
        $later = $this->addDailyFact($fixture, '2026-09-06', $connection);
        // Make the read order explicit: the GAM clue exists only on the later row.
        $earlier->update(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAB', 'report_dimension_id' => $plainDimension->id]);
        $later->update(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAC']);
        $this->addHourlyFact($fixture, '2026-09-05', $connection)->update(['report_dimension_id' => $plainDimension->id]);
        $before = $this->financialSnapshot();
        $operation = $this->operation();

        $result = $this->runOperation('discover', $operation, $fixture);

        $this->assertPublicResult($result, $operation, $fixture);
        $this->assertSame(2, $result['counts']['sources']);
        $this->assertSame(4, $result['counts']['daily_facts']);
        $this->assertSame(1, $result['counts']['hourly_facts']);
        $this->assertSame(3, $result['counts']['blocked_facts']);
        $this->assertSame(3, $result['reasons']['UNBOUND_SOURCE']);
        $this->assertSame([], $fixture['google']->calls);
        $this->assertSame($before, $this->financialSnapshot());
    }

    public function test_discovery_splits_only_stored_contiguous_dates_at_gaps_months_and_cutover(): void
    {
        $fixture = $this->context();
        foreach (['2026-09-04', '2026-09-30', '2026-10-01'] as $date) $this->addDailyFact($fixture, $date);
        $this->addForwardFact($fixture, '2026-10-10');
        $before = $this->financialSnapshot();
        $operation = $this->operation();

        $result = $this->runOperation('discover', $operation, $fixture);
        $inventory = app(\App\Services\Reporting\GamHistoricalInventory::class)->discover();

        $this->assertPublicResult($result, $operation, $fixture);
        $this->assertSame(6, $result['counts']['daily_facts']);
        $this->assertSame(4, $result['counts']['windows']);
        $this->assertSame(1, $result['counts']['forward_facts']);
        $this->assertSame([
            ['2026-09-01', '2026-09-02'], ['2026-09-04', '2026-09-04'],
            ['2026-09-30', '2026-09-30'], ['2026-10-01', '2026-10-01'],
        ], array_map(fn ($window) => [$window['from'], $window['to']], $inventory['windows']));
        $this->assertSame(5, array_sum(array_map(fn ($window) => count($window['fact_ids']), $inventory['windows'])));
        $this->assertSame([], $fixture['google']->calls);
        $this->assertSame($before, $this->financialSnapshot());
    }

    public function test_an_inactive_binding_remains_counted_and_is_not_prepared(): void
    {
        $fixture = $this->context();
        $fixture['connection']->update(['is_enabled' => false]);
        $before = $this->financialSnapshot();
        $operation = $this->operation();
        $result = $this->runOperation('discover', $operation, $fixture);
        $this->runOperation('prepare', $operation, $fixture);

        $this->assertSame(2, $result['counts']['daily_facts']);
        $this->assertSame(2, $result['counts']['blocked_facts']);
        $this->assertSame(0, $result['counts']['windows']);
        $this->assertSame(2, ((array) $result['reasons'])['INACTIVE_BINDING']);
        $this->assertSame([], $fixture['google']->calls);
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertDatabaseCount('gam_revenue_corrections', 0);
    }

    public function test_discovery_does_not_install_or_repair_a_missing_verified_scope(): void
    {
        $fixture = $this->context();
        $configuration = $fixture['connection']->configuration;
        unset($configuration['site_report_scope']);
        $fixture['connection']->update(['configuration' => $configuration]);
        $before = $this->financialSnapshot();
        $operation = $this->operation();

        $result = $this->runOperation('discover', $operation, $fixture);
        $this->runOperation('prepare', $operation, $fixture);

        $this->assertSame(2, $result['counts']['blocked_facts']);
        $this->assertSame(0, $result['counts']['windows']);
        $this->assertSame(2, ((array) $result['reasons'])['UNVERIFIED_SCOPE']);
        $this->assertSame([], $fixture['google']->calls);
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertDatabaseCount('gam_revenue_corrections', 0);
    }

    public function test_discovery_and_status_are_read_only_and_never_contact_google(): void
    {
        $fixture = $this->context();
        $operation = $this->operation();
        $before = $this->financialSnapshot();

        $discovered = $this->runOperation('discover', $operation, $fixture);
        $status = $this->runOperation('status', $operation, $fixture);

        $this->assertPublicResult($discovered, $operation, $fixture);
        $this->assertPublicResult($status, $operation, $fixture);
        $this->assertSame('OK', $discovered['outcome']);
        $this->assertSame($discovered['counts'], $status['counts']);
        $this->assertNull($discovered['digest']);
        $this->assertSame([], $fixture['google']->calls);
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertDatabaseCount('gam_revenue_corrections', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('unauthorizedActorCases')]
    public function test_invalid_actors_are_rejected_before_discovery_or_google_calls(string $kind): void
    {
        $fixture = $this->context();
        $actor = $fixture['admin'];
        $fingerprint = hash('sha256', strtolower(trim($actor->email)));
        if ($kind === 'unknown') $fingerprint = hash('sha256', 'unknown-synthetic-admin@example.test');
        if ($kind === 'publisher') $fingerprint = hash('sha256', strtolower(trim($fixture['publisherUser']->email)));
        if ($kind === 'inactive') $actor->forceFill(['status' => 'SUSPENDED'])->save();
        if ($kind === 'locked') $actor->forceFill(['locked_until' => now()->addHour()])->save();
        if ($kind === 'unverified') $actor->forceFill(['email_verified_at' => null])->save();
        if ($kind === 'inactive_organization') $actor->organization->update(['status' => 'SUSPENDED']);
        if (str_starts_with($kind, 'missing:')) {
            $permissions = ['reporting.admin.view', 'reporting.import', 'finance.adjustments.approve'];
            $role = Role::create(['name' => 'SYNTHETIC_OPERATION_REVIEWER', 'display_name' => 'Synthetic reviewer', 'is_system' => false]);
            $role->permissions()->sync(Permission::whereIn('name', array_diff($permissions, [substr($kind, 8)]))->pluck('id'));
            $actor->roles()->sync([$role->id]);
        }
        $before = $this->financialSnapshot();
        $operation = $this->operation();

        $result = app(GamHistoricalOperation::class)->run('discover', $operation, $fingerprint);

        $this->assertPublicResult($result, $operation, $fixture);
        $this->assertSame('BLOCKED', $result['outcome']);
        $this->assertNull($result['digest']);
        $this->assertSame([], $fixture['google']->calls);
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertDatabaseCount('gam_revenue_corrections', 0);
    }

    public static function unauthorizedActorCases(): array
    {
        $cases = ['unknown', 'publisher', 'inactive', 'locked', 'unverified', 'inactive_organization',
            'missing:reporting.admin.view', 'missing:reporting.import', 'missing:finance.adjustments.approve'];

        return array_combine($cases, array_map(fn ($case) => [$case], $cases));
    }

    public function test_an_operation_is_bound_to_the_exact_authorized_actor(): void
    {
        $fixture = $this->context();
        $operation = $this->operation();
        $this->runOperation('discover', $operation, $fixture);
        $otherAdmin = $this->makeUser($fixture['admin']->organization, RoleName::SuperAdmin);
        $before = $this->financialSnapshot();

        foreach (['status', 'prepare', 'poll', 'apply'] as $mode) {
            $result = app(GamHistoricalOperation::class)->run($mode, $operation, hash('sha256', strtolower(trim($otherAdmin->email))));
            $this->assertSame('BLOCKED', $result['outcome']);
            $this->assertPublicResult($result, $operation, $fixture);
        }

        $this->assertSame([], $fixture['google']->calls);
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertDatabaseCount('gam_revenue_corrections', 0);
    }

    public function test_prepare_and_poll_collect_reviewed_evidence_without_mutating_financial_or_forward_state(): void
    {
        $fixture = $this->context();
        $operation = $this->operation();
        $before = $this->financialSnapshot();
        $discovered = $this->runOperation('discover', $operation, $fixture);
        $prepared = $this->runOperation('prepare', $operation, $fixture);

        $this->assertPublicResult($prepared, $operation, $fixture);
        $this->assertSame(array_keys($discovered['counts']), array_keys($prepared['counts']));
        $this->assertNull($prepared['digest']);
        $candidate = GamRevenueCorrection::query()->sole();
        $this->assertSame('PENDING', $candidate->status);
        $this->assertSame($fixture['admin']->id, $candidate->actor_id);
        $this->assertSame('2026-09-01', $candidate->period_start);
        $this->assertSame('2026-09-02', $candidate->period_end);
        $this->assertCount(1, $fixture['google']->queries);
        $this->assertSame($before, $this->financialSnapshot());

        $this->travel(16)->seconds();
        $reviewed = $this->runOperation('poll', $operation, $fixture);

        $this->assertPublicResult($reviewed, $operation, $fixture);
        $this->assertSame('OK', $reviewed['outcome']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $reviewed['digest']);
        $this->assertSame('READY', $candidate->fresh()->status);
        $this->assertSame($before, $this->financialSnapshot());
        $calls = $fixture['google']->calls;
        $repeated = $this->runOperation('poll', $operation, $fixture);
        $this->assertSame($reviewed['digest'], $repeated['digest']);
        $this->assertSame($calls, $fixture['google']->calls);
        $this->assertSame($before, $this->financialSnapshot());
        Http::assertNothingSent();
    }

    public function test_one_missing_exact_site_day_blocks_the_entire_window_without_zero_filling(): void
    {
        $fixture = $this->context();
        $fixture['google']->missingDays = ['2026-09-02'];
        $operation = $this->operation();
        $before = $this->financialSnapshot();
        $this->runOperation('discover', $operation, $fixture);
        $this->runOperation('prepare', $operation, $fixture);
        $this->travel(16)->seconds();

        $reviewed = $this->runOperation('poll', $operation, $fixture);
        $applied = $this->runOperation('apply', $operation, $fixture, digest: $reviewed['digest'] ?? str_repeat('0', 64));

        $this->assertPublicResult($reviewed, $operation, $fixture);
        $this->assertPublicResult($applied, $operation, $fixture);
        $this->assertSame('BLOCKED', $applied['outcome']);
        $candidate = GamRevenueCorrection::query()->sole();
        $this->assertSame('BLOCKED', $candidate->status);
        $this->assertArrayNotHasKey('2026-09-02', $candidate->job['result']['days']);
        $this->assertNull($candidate->proposal['totals']);
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 0);
    }

    public function test_apply_requires_the_exact_operation_digest_and_repeated_apply_preserves_the_receipt(): void
    {
        $fixture = $this->context();
        [$operation, $reviewed] = $this->reviewedOperation($fixture);
        $before = $this->financialSnapshot();
        $sourceBefore = $fixture['connection']->fresh()->getAttributes();
        $forwardJobBefore = $fixture['job']->fresh()->getAttributes();
        $candidate = GamRevenueCorrection::query()->sole();
        $this->assertSiteHistoryNotice($fixture);

        foreach ([null, str_repeat('0', 64), $candidate->digest] as $wrongDigest) {
            $rejected = $this->runOperation('apply', $operation, $fixture, digest: $wrongDigest);
            $this->assertPublicResult($rejected, $operation, $fixture);
            $this->assertSame('BLOCKED', $rejected['outcome']);
            $this->assertSame($before, $this->financialSnapshot());
        }

        $applied = $this->runOperation('apply', $operation, $fixture, digest: $reviewed['digest']);

        $this->assertPublicResult($applied, $operation, $fixture);
        $this->assertSame('OK', $applied['outcome']);
        $this->assertSame('APPLIED', $candidate->fresh()->status);
        foreach ($fixture['facts'] as $original) {
            $fact = $original->fresh();
            $this->assertSame(8, (int) $fact->revision);
            $this->assertSame(201, (int) $fact->gross_revenue_minor);
            $this->assertSame(133, (int) $fact->publisher_earnings_minor);
            $this->assertSame($fixture['version']->id, $fact->revenue_rule_version_id);
        }
        $this->assertSiteHistoryNotice($fixture);
        $this->assertSame($sourceBefore, $fixture['connection']->fresh()->getAttributes());
        $this->assertSame($forwardJobBefore, $fixture['job']->fresh()->getAttributes());
        $receipt = GamRevenueCorrectionReceipt::query()->sole()->getAttributes();
        $after = $this->financialSnapshot();
        $calls = $fixture['google']->calls;

        $repeated = $this->runOperation('apply', $operation, $fixture, digest: $reviewed['digest']);
        $status = $this->runOperation('status', $operation, $fixture);

        $this->assertPublicResult($repeated, $operation, $fixture);
        $this->assertPublicResult($status, $operation, $fixture);
        $this->assertSame('OK', $repeated['outcome']);
        $this->assertSame($receipt, GamRevenueCorrectionReceipt::query()->sole()->getAttributes());
        $this->assertSame($after, $this->financialSnapshot());
        $this->assertSame($calls, $fixture['google']->calls);
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 1);
        $this->assertDatabaseCount('report_import_jobs', 2);
        $this->assertDatabaseCount('reconciliation_runs', 1);
        $this->assertDatabaseCount('monthly_reports', 0);
        $this->assertDatabaseCount('publisher_statements', 0);
        $this->assertDatabaseCount('publisher_payments', 0);
    }

    public function test_new_historical_inventory_after_review_blocks_application_of_the_old_digest(): void
    {
        $fixture = $this->context();
        [$operation, $reviewed] = $this->reviewedOperation($fixture);
        // A new day outside the candidate's range must still invalidate the operation review.
        $this->addDailyFact($fixture, '2026-09-05');
        $before = $this->financialSnapshot();
        $calls = $fixture['google']->calls;

        $result = $this->runOperation('apply', $operation, $fixture, digest: $reviewed['digest']);

        $this->assertPublicResult($result, $operation, $fixture);
        $this->assertSame('BLOCKED', $result['outcome']);
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertSame($calls, $fixture['google']->calls);
        $this->assertSame('READY', GamRevenueCorrection::query()->sole()->status);
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 0);
    }

    #[DataProvider('uncertainCandidateCases')]
    public function test_existing_starting_and_pending_attempts_are_never_silently_replaced(string $status, bool $expired): void
    {
        $fixture = $this->context();
        $candidate = app(GamRevenueCorrectionService::class)->start($fixture['site'], '2026-09-01', '2026-09-02', $fixture['admin']);
        $candidate->update(['status' => $status]);
        if ($expired) $this->travel(61)->minutes();
        $job = $candidate->fresh()->job;
        $before = $this->financialSnapshot();
        $operation = $this->operation();
        $this->runOperation('discover', $operation, $fixture);

        foreach (['prepare', 'prepare', 'status', 'discover'] as $mode) {
            $result = $this->runOperation($mode, $operation, $fixture);
            $this->assertPublicResult($result, $operation, $fixture);
            $this->assertDatabaseCount('gam_revenue_corrections', 1);
            $this->assertSame($candidate->id, GamRevenueCorrection::query()->sole()->id);
            $this->assertSame($status, $candidate->fresh()->status);
            $this->assertSame($job, $candidate->fresh()->job);
            $this->assertCount(1, $fixture['google']->queries);
        }
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 0);
    }

    public static function uncertainCandidateCases(): array
    {
        return ['starting' => ['STARTING', false], 'pending' => ['PENDING', false],
            'expired starting' => ['STARTING', true], 'expired pending' => ['PENDING', true]];
    }

    public function test_preparation_respects_the_requested_batch_and_six_candidate_capacity(): void
    {
        $fixture = $this->context();
        $fixture['facts']->last()->update(['report_date' => '2026-09-03']);
        foreach (['2026-09-05', '2026-09-07', '2026-09-09', '2026-09-11', '2026-09-13'] as $date) $this->addDailyFact($fixture, $date);
        $before = $this->financialSnapshot();
        $operation = $this->operation();
        $discovered = $this->runOperation('discover', $operation, $fixture);
        $this->assertSame(7, $discovered['counts']['windows']);

        $invalid = $this->runOperation('prepare', $operation, $fixture, limit: 7);
        $this->assertSame('BLOCKED', $invalid['outcome']);
        $this->assertSame([], $fixture['google']->calls);
        $this->runOperation('prepare', $operation, $fixture, limit: 2);
        $this->assertDatabaseCount('gam_revenue_corrections', 2);
        $this->assertCount(2, $fixture['google']->queries);
        $this->runOperation('prepare', $operation, $fixture, limit: 6);
        $this->runOperation('prepare', $operation, $fixture, limit: 6);

        $this->assertDatabaseCount('gam_revenue_corrections', 6);
        $this->assertCount(6, $fixture['google']->queries);
        $this->assertSame($before, $this->financialSnapshot());
    }

    #[DataProvider('corruptedReviewCases')]
    public function test_ready_status_and_even_a_valid_candidate_digest_do_not_replace_substantive_review(string $kind, string $failedCheck): void
    {
        $fixture = $this->context();
        $operation = $this->operation();
        $this->runOperation('discover', $operation, $fixture);
        $this->runOperation('prepare', $operation, $fixture);
        $this->travel(16)->seconds();
        $candidate = app(GamRevenueCorrectionService::class)->poll(GamRevenueCorrection::query()->sole(), $fixture['admin']);
        $this->assertSame('READY', $candidate->status);
        $proposal = $candidate->proposal;
        $context = $candidate->context;
        if ($kind === 'allocation') $proposal['days'][0]['projected']['publisher_earnings_minor']++;
        if ($kind === 'totals') $proposal['totals']['new_gross_minor']++;
        if ($kind === 'hostname') $context['hostname'] = 'other.test.example';
        if ($kind === 'days') array_pop($proposal['days']);
        $candidate->fill(['proposal' => $proposal, 'context' => $context]);
        if ($kind !== 'digest') {
            // Simulate internally self-consistent persisted evidence with an invalid financial claim.
            $candidate->digest = GamRevenueCorrectionService::hash([
                'version' => 1, 'candidate_id' => $candidate->id, 'actor_id' => $candidate->actor_id,
                'context' => $candidate->context, 'snapshot' => $candidate->snapshot['fingerprint'],
                'query_hash' => $candidate->query_hash, 'job' => $candidate->job, 'proposal' => $candidate->proposal,
            ]);
        } else {
            $candidate->digest = str_repeat('0', 64);
        }
        // Deliberately bypass immutable model guards to simulate corrupted persisted JSON.
        DB::table('gam_revenue_corrections')->where('id', $candidate->id)->update([
            'context' => json_encode($candidate->context, JSON_THROW_ON_ERROR),
            'proposal' => json_encode($candidate->proposal, JSON_THROW_ON_ERROR), 'digest' => $candidate->digest,
        ]);
        $before = $this->financialSnapshot();

        $result = $this->runOperation('poll', $operation, $fixture);

        $this->assertPublicResult($result, $operation, $fixture);
        $this->assertSame('BLOCKED', $result['outcome']);
        $this->assertSame(0, $result['counts']['ready']);
        $this->assertNull($result['digest']);
        $manifest = json_decode(file_get_contents(storage_path('app/private/gam-historical-operations/'.$operation.'.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($manifest['windows'][0]['review']['passed']);
        $this->assertFalse($manifest['windows'][0]['review']['checks'][$failedCheck]);
        $this->assertSame('READY', $candidate->fresh()->status);
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 0);
    }

    public static function corruptedReviewCases(): array
    {
        return ['allocation' => ['allocation', 'arithmetic'], 'totals' => ['totals', 'reconciliation'],
            'hostname' => ['hostname', 'hostname'], 'missing day' => ['days', 'days'], 'digest' => ['digest', 'candidate_digest']];
    }

    public function test_mysql_style_json_object_key_reordering_preserves_the_review_and_digest(): void
    {
        $fixture = $this->context();
        [$operation, $reviewed] = $this->reviewedOperation($fixture);
        $candidate = GamRevenueCorrection::query()->sole();
        $reverseObjects = function (mixed $value) use (&$reverseObjects): mixed {
            if (! is_array($value)) return $value;
            $value = array_map($reverseObjects, $value);

            return array_is_list($value) ? $value : array_reverse($value, preserve_keys: true);
        };
        $reorderedJson = [];
        foreach (['context', 'snapshot', 'job', 'proposal'] as $attribute) {
            $reorderedJson[$attribute] = json_encode($reverseObjects($candidate->{$attribute}), JSON_THROW_ON_ERROR);
        }
        // MySQL can reorder object keys without an application-level evidence update.
        DB::table('gam_revenue_corrections')->where('id', $candidate->id)->update($reorderedJson);
        $before = $this->financialSnapshot();

        $reordered = $this->runOperation('poll', $operation, $fixture);

        $this->assertSame('OK', $reordered['outcome']);
        $this->assertSame($reviewed['digest'], $reordered['digest']);
        $this->assertSame($before, $this->financialSnapshot());
        $applied = $this->runOperation('apply', $operation, $fixture, digest: $reviewed['digest']);
        $this->assertSame('OK', $applied['outcome']);
        $this->assertSame('APPLIED', $candidate->fresh()->status);
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 1);
    }

    public function test_verified_forward_updates_do_not_stale_a_historical_review(): void
    {
        $fixture = $this->context();
        $forward = $this->addForwardFact($fixture, '2026-10-10');
        [$operation, $reviewed] = $this->reviewedOperation($fixture);
        $forward->increment('gross_revenue_minor');
        $newForward = $this->addForwardFact($fixture, '2026-10-10', hourly: true);
        $forwardBefore = $forward->fresh()->getAttributes();
        $hourlyBefore = $newForward->fresh()->getAttributes();

        $applied = $this->runOperation('apply', $operation, $fixture, digest: $reviewed['digest']);

        $this->assertSame('OK', $applied['outcome']);
        $this->assertSame($forwardBefore, $forward->fresh()->getAttributes());
        $this->assertSame($hourlyBefore, $newForward->fresh()->getAttributes());
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 1);
    }

    public function test_legacy_post_cutover_rows_are_blocked_instead_of_claimed_as_verified_forward_facts(): void
    {
        $fixture = $this->context();
        $this->addDailyFact($fixture, '2026-10-10');
        $before = $this->financialSnapshot();
        $operation = $this->operation();

        $result = $this->runOperation('discover', $operation, $fixture);

        $this->assertSame(0, $result['counts']['forward_facts']);
        $this->assertSame(1, $result['counts']['blocked_facts']);
        $this->assertSame(1, $result['reasons']['UNVERIFIED_SCOPE']);
        $this->assertSame([], $fixture['google']->calls);
        $this->assertSame($before, $this->financialSnapshot());
    }

    #[DataProvider('expiredTerminalCases')]
    public function test_a_new_operation_can_explicitly_replace_expired_terminal_evidence_while_the_old_operation_preserves_it(string $status): void
    {
        $fixture = $this->context();
        [$oldOperation] = $this->reviewedOperation($fixture);
        $expired = GamRevenueCorrection::query()->sole();
        $expired->update(['status' => $status]);
        $this->travel(61)->minutes();
        $before = $this->financialSnapshot();

        $this->runOperation('status', $oldOperation, $fixture);
        $this->runOperation('prepare', $oldOperation, $fixture);
        $this->assertDatabaseCount('gam_revenue_corrections', 1);
        $this->assertSame($status, $expired->fresh()->status);
        $this->assertCount(1, $fixture['google']->queries);

        $newOperation = $this->operation();
        $this->runOperation('discover', $newOperation, $fixture);
        $prepared = $this->runOperation('prepare', $newOperation, $fixture);

        $this->assertPublicResult($prepared, $newOperation, $fixture);
        $this->assertSame('OK', $prepared['outcome']);
        $this->assertDatabaseCount('gam_revenue_corrections', 2);
        $this->assertSame('SUPERSEDED', $expired->fresh()->status);
        $replacement = GamRevenueCorrection::query()->whereKeyNot($expired->id)->sole();
        $this->assertSame('PENDING', $replacement->status);
        $this->assertSame($expired->site_id, $replacement->site_id);
        $this->assertSame($expired->period_start, $replacement->period_start);
        $this->assertSame($expired->period_end, $replacement->period_end);
        $manifest = json_decode(file_get_contents(storage_path('app/private/gam-historical-operations/'.$newOperation.'.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([$expired->id], $manifest['windows'][0]['candidate_history']);
        $this->assertSame($replacement->id, $manifest['windows'][0]['candidate_id']);
        $this->assertCount(2, $fixture['google']->queries);
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 0);
    }

    public static function expiredTerminalCases(): array
    {
        return ['ready' => ['READY'], 'blocked' => ['BLOCKED'], 'failed' => ['FAILED']];
    }

    public function test_an_interruption_after_commit_recovers_the_receipt_without_a_second_revision(): void
    {
        $fixture = $this->context();
        [$operation, $reviewed] = $this->reviewedOperation($fixture);
        $candidate = GamRevenueCorrection::query()->sole();
        $manifestPath = storage_path('app/private/gam-historical-operations/'.$operation.'.json');
        $manifestBefore = file_get_contents($manifestPath);
        // Model the service committing before the operation can persist its checkpoint.
        app(GamRevenueCorrectionService::class)->apply($candidate, $candidate->digest,
            'Reviewed synthetic operation evidence before interrupted checkpoint.', $fixture['admin']);
        $this->assertSame($manifestBefore, file_get_contents($manifestPath));
        $afterCommit = $this->financialSnapshot();
        $receipt = GamRevenueCorrectionReceipt::query()->sole();
        $calls = $fixture['google']->calls;

        $status = $this->runOperation('status', $operation, $fixture);
        $repeated = $this->runOperation('apply', $operation, $fixture, digest: $reviewed['digest']);

        $this->assertPublicResult($status, $operation, $fixture);
        $this->assertPublicResult($repeated, $operation, $fixture);
        $this->assertSame('OK', $status['outcome']);
        $this->assertSame('OK', $repeated['outcome']);
        $this->assertSame($reviewed['digest'], $status['digest']);
        $this->assertSame($reviewed['digest'], $repeated['digest']);
        $this->assertSame(1, $status['counts']['applied']);
        $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($receipt->id, $manifest['windows'][0]['receipt_id']);
        $this->assertSame('APPLIED', $manifest['windows'][0]['state']);
        $this->assertSame($afterCommit, $this->financialSnapshot());
        $this->assertSame($calls, $fixture['google']->calls);
        $this->assertDatabaseCount('gam_revenue_correction_receipts', 1);
        $this->assertDatabaseCount('report_import_jobs', 2);
        $this->assertDatabaseCount('reconciliation_runs', 1);
    }

    public function test_polling_an_in_progress_job_preserves_its_identity_and_does_not_start_another_job(): void
    {
        $fixture = $this->context();
        $fixture['google']->reportStatus = 'IN_PROGRESS';
        $operation = $this->operation();
        $before = $this->financialSnapshot();
        $this->runOperation('discover', $operation, $fixture);
        $this->runOperation('prepare', $operation, $fixture);
        $candidate = GamRevenueCorrection::query()->sole();
        $jobId = $candidate->job['id'];
        $this->travel(16)->seconds();

        $result = $this->runOperation('poll', $operation, $fixture);
        $this->runOperation('prepare', $operation, $fixture);

        $this->assertPublicResult($result, $operation, $fixture);
        $this->assertNull($result['digest']);
        $this->assertSame('PENDING', $candidate->fresh()->status);
        $this->assertSame($jobId, $candidate->fresh()->job['id']);
        $this->assertSame(1, $candidate->fresh()->job['polls']);
        $this->assertCount(1, $fixture['google']->queries);
        $this->assertNotContains('download', $fixture['google']->calls);
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertDatabaseCount('gam_revenue_corrections', 1);
    }

    public function test_the_command_writes_only_sanitized_json_to_a_private_result_file(): void
    {
        $fixture = $this->context();
        $operation = $this->operation();
        $this->runOperation('discover', $operation, $fixture);
        $this->runOperation('prepare', $operation, $fixture);
        $this->travel(16)->seconds();
        $fixture['google']->onStatus = static function (): void { echo 'Synthetic private Google trace news.test.example gross_revenue_minor=123'; };
        $before = $this->financialSnapshot();
        $directory = storage_path('framework/testing/gam-historical-result-'.$operation);
        mkdir($directory, 0700, true);
        $path = $directory.'/result.json';

        try {
            $exit = Artisan::call('reporting:gam-historical-operation', [
                'mode' => 'poll', '--operation' => $operation,
                '--actor-fingerprint' => hash('sha256', strtolower(trim($fixture['admin']->email))),
                '--result-file' => $path,
            ]);

            $this->assertSame(0, $exit);
            $this->assertSame('', Artisan::output());
            $this->assertFileExists($path);
            $this->assertSame(0600, fileperms($path) & 0777);
            $json = file_get_contents($path);
            $decoded = json_decode($json, false, flags: JSON_THROW_ON_ERROR);
            $this->assertInstanceOf(\stdClass::class, $decoded->reasons);
            $this->assertPublicResult(json_decode($json, true, flags: JSON_THROW_ON_ERROR), $operation, $fixture);
            $this->assertSame(1, $decoded->counts->ready);
            $this->assertStringNotContainsString('Synthetic private Google trace', $json);
            $privateLog = storage_path('app/private/gam-historical-operations/'.$operation.'.console.log');
            $this->assertStringContainsString('Synthetic private Google trace', file_get_contents($privateLog));
            $this->assertSame(0600, fileperms($privateLog) & 0777);
            $this->assertSame($before, $this->financialSnapshot());
        } finally {
            if (is_file($path)) unlink($path);
            rmdir($directory);
        }
    }

    public function test_invalid_result_destinations_are_rejected_before_google_or_financial_work(): void
    {
        $fixture = $this->context();
        $operation = $this->operation();
        $this->runOperation('discover', $operation, $fixture);
        $before = $this->financialSnapshot();
        $directory = storage_path('framework/testing/gam-historical-result-'.$operation);
        mkdir($directory, 0700, true);
        chmod($directory, 0755);
        $path = $directory.'/result.json';
        $arguments = ['mode' => 'prepare', '--operation' => $operation,
            '--actor-fingerprint' => hash('sha256', strtolower(trim($fixture['admin']->email))), '--result-file' => $path];

        try {
            $this->assertSame(1, Artisan::call('reporting:gam-historical-operation', $arguments));
            $this->assertSame('', Artisan::output());
            $this->assertFileDoesNotExist($path);
            chmod($directory, 0700);
            clearstatcache(true, $directory);
            file_put_contents($path, 'Synthetic existing result must survive.');
            $this->assertSame(1, Artisan::call('reporting:gam-historical-operation', $arguments));
            $this->assertSame('Synthetic existing result must survive.', file_get_contents($path));
            $arguments['--result-file'] = $directory.'/wrong-name.json';
            $this->assertSame(1, Artisan::call('reporting:gam-historical-operation', $arguments));
            foreach ([public_path('gam-historical-result-'.$operation), storage_path('app/public/gam-historical-result-'.$operation)] as $publicDirectory) {
                mkdir($publicDirectory, 0700, true);
                $publicResult = $publicDirectory.'/result.json';
                try {
                    $this->assertSame(0700, fileperms($publicDirectory) & 0777);
                    $arguments['--result-file'] = $publicResult;
                    $this->assertSame(1, Artisan::call('reporting:gam-historical-operation', $arguments));
                    $this->assertFileDoesNotExist($publicResult);
                } finally {
                    if (is_file($publicResult)) unlink($publicResult);
                    rmdir($publicDirectory);
                }
            }
            $this->assertSame([], $fixture['google']->calls);
            $this->assertSame($before, $this->financialSnapshot());
            $this->assertDatabaseCount('gam_revenue_corrections', 0);
        } finally {
            if (is_file($path)) unlink($path);
            if (is_file($directory.'/wrong-name.json')) unlink($directory.'/wrong-name.json');
            rmdir($directory);
        }
    }

    private function assertSiteHistoryNotice(array $fixture): void
    {
        $this->get(route('admin.sites.show', $fixture['site']))->assertOk()
            ->assertSee('Earlier stored reporting through')
            ->assertSee('is outside forward synchronization.')
            ->assertSee('Corrected days are documented in private correction receipts.')
            ->assertSee('Unresolved history still requires verification before settlement.')
            ->assertDontSee('has not been revalidated');
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

    private function reviewedOperation(array $fixture): array
    {
        $operation = $this->operation();
        $this->runOperation('discover', $operation, $fixture);
        $this->runOperation('prepare', $operation, $fixture);
        $this->travel(16)->seconds();
        $reviewed = $this->runOperation('poll', $operation, $fixture);
        $this->assertSame('OK', $reviewed['outcome']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $reviewed['digest']);

        return [$operation, $reviewed];
    }

    private function operation(): string
    {
        $operation = bin2hex(random_bytes(32));
        $this->operationFiles[] = storage_path('app/private/gam-historical-operations/'.$operation);

        return $operation;
    }

    private function runOperation(string $mode, string $operation, array $fixture, int $limit = 1, ?string $digest = null): array
    {
        return app(GamHistoricalOperation::class)->run($mode, $operation,
            hash('sha256', strtolower(trim($fixture['admin']->email))), $limit, $digest);
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

    private function assertPublicResult(array $result, string $operation, array $fixture): void
    {
        $keys = array_keys($result);
        sort($keys);
        $this->assertSame(['counts', 'digest', 'operation', 'outcome', 'reason', 'reasons', 'schema_version'], $keys);
        $this->assertSame(1, $result['schema_version']);
        $this->assertSame($operation, $result['operation']);
        $this->assertContains($result['outcome'], ['OK', 'BLOCKED', 'FAILED']);
        $this->assertContains($result['reason'], GamHistoricalOperation::REASONS);
        if ($result['digest'] !== null) $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $result['digest']);
        $this->assertIsArray($result['counts']);
        $countKeys = array_keys($result['counts']);
        sort($countKeys);
        $this->assertSame(['applied', 'blocked', 'blocked_facts', 'corrected_facts', 'daily_facts', 'eligible_windows',
            'forward_facts', 'hourly_facts', 'pending', 'ready', 'sources', 'windows'], $countKeys);
        $this->assertIsArray($result['reasons']);
        foreach ($result['counts'] as $count) {
            $this->assertIsInt($count);
            $this->assertGreaterThanOrEqual(0, $count);
        }
        foreach ($result['reasons'] as $reason => $count) {
            $this->assertContains($reason, ['HOURLY_FACTS', 'UNBOUND_SOURCE', 'INACTIVE_BINDING', 'UNVERIFIED_SCOPE',
                'OUTSIDE_BINDING', 'FORWARD_SCOPE', 'INCOMPLETE_DAY', 'MULTIPLE_FACTS', 'MIXED_IDENTITY', 'RECEIPT_MISMATCH',
                'CANDIDATE_BLOCKED', 'CANDIDATE_EXPIRED', 'CANDIDATE_UNCERTAIN', 'REVIEW_REQUIRED']);
            $this->assertIsInt($count);
            $this->assertGreaterThan(0, $count);
        }
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        foreach ([$fixture['admin']->email, $fixture['admin']->id, $fixture['publisher']->id, $fixture['site']->id,
            $fixture['binding']->id, $fixture['connection']->id, 'news.test.example', 'other.test.example',
            'gross_revenue_minor', 'publisher_earnings_minor', 'network_code', 'ad_unit_id', 'Synthetic'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $json);
        }
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

}
