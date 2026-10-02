<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\DailyReport;
use App\Models\FinancialPeriod;
use App\Models\GamConnection;
use App\Models\ReportImportJob;
use App\Models\ReportSource;
use App\Models\ReportSourceConnection;
use App\Models\RevenueRule;
use App\Models\RevenueRuleVersion;
use App\Models\SiteGamReportBinding;
use App\Services\Reporting\GamAdUnitReportClient;
use App\Services\Reporting\GamRevenueCorrectionService;
use App\Services\Reporting\ReportDimensionResolver;
use App\Services\Reporting\SiteGamReportMetrics;
use App\Services\Reporting\SiteGamReportScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

/** Requires an isolated MySQL test database; SQLite cannot prove this invariant. */
final class GamRevenueCorrectionConcurrencyTest extends TestCase
{
    use DatabaseMigrations, InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites;

    #[DataProvider('concurrentChanges')]
    public function test_apply_revalidates_current_committed_evidence_despite_an_older_repeatable_read_snapshot(string $kind): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This regression requires two real MySQL connections and REPEATABLE READ.');
        }

        $fixture = $this->committedFixture();
        $service = app(GamRevenueCorrectionService::class);
        $candidate = $service->start($fixture['site'], '2026-09-01', '2026-09-01', $fixture['admin']);
        $this->travel(16)->seconds();
        $candidate = $service->poll($candidate, $fixture['admin']);
        $this->assertSame('READY', $candidate->status, json_encode($candidate->proposal));

        $reader = DB::connection();
        $this->assertSame(0, $reader->transactionLevel(), 'Fixtures must be committed and visible to the independent writer.');
        config(['database.connections.gam_correction_concurrent_writer' => $reader->getConfig()]);
        DB::purge('gam_correction_concurrent_writer');
        $writer = DB::connection('gam_correction_concurrent_writer');
        $this->assertNotSame(
            $reader->selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id,
            $writer->selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id,
        );
        $this->assertSame(0, $writer->transactionLevel());
        $this->assertSame(1, $writer->table('gam_revenue_corrections')->where('id', $candidate->id)->count());

        // The setting affects only the next transaction, not future tests.
        $reader->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $reader->beginTransaction();
        try {
            // Ordinary reads establish an old consistent snapshot before B commits.
            $this->assertSame('OPEN', $reader->table('financial_periods')->where('id', $fixture['period']->id)->value('status'));
            $this->assertSame(123, (int) $reader->table('daily_reports')->where('id', $fixture['fact']->id)->value('gross_revenue_minor'));
            $this->assertSame(0, $reader->table('publisher_statements')->count());

            match ($kind) {
                'period' => $writer->table('financial_periods')->where('id', $fixture['period']->id)->update(['status' => 'CLOSED', 'updated_at' => now()]),
                'fact' => $writer->table('daily_reports')->where('id', $fixture['fact']->id)->update(['gross_revenue_minor' => 124, 'updated_at' => now()]),
                'statement' => $writer->table('publisher_statements')->insert([
                    'id' => (string) Str::ulid(), 'organization_id' => $fixture['publisher']->organization_id,
                    'publisher_id' => $fixture['publisher']->id, 'financial_period_id' => $fixture['period']->id,
                    'statement_number' => 'SYNTHETIC-CONCURRENT-DRAFT', 'status' => 'DRAFT', 'currency' => 'USD',
                    'line_items' => '[]', 'snapshot' => '{}', 'snapshot_hash' => hash('sha256', 'synthetic-concurrent-statement'),
                    'created_at' => now(), 'updated_at' => now(),
                ]),
            };
            $beforeApply = $this->snapshot($writer);

            // Prove A really has a stale snapshot; a SQLite or READ COMMITTED
            // execution must not accidentally count as the intended regression.
            $this->assertSame('OPEN', $reader->table('financial_periods')->where('id', $fixture['period']->id)->value('status'));
            $this->assertSame(123, (int) $reader->table('daily_reports')->where('id', $fixture['fact']->id)->value('gross_revenue_minor'));
            $this->assertSame(0, $reader->table('publisher_statements')->count());

            try {
                $service->apply($candidate, $candidate->digest, 'Reviewed before a concurrent committed financial change.', $fixture['admin']);
                $this->fail('Apply must use current locking reads and reject the changed committed evidence.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('correction', $exception->errors());
            }

            $this->assertSame(0, $reader->table('gam_revenue_correction_receipts')->count());
            $this->assertSame(1, $reader->table('report_import_jobs')->count());
            $this->assertSame('READY', $reader->table('gam_revenue_corrections')->where('id', $candidate->id)->value('status'));
            $this->assertSame($beforeApply, $this->snapshot($writer));
        } finally {
            while ($reader->transactionLevel() > 0) $reader->rollBack();
            DB::purge('gam_correction_concurrent_writer');
        }

        $this->assertSame($beforeApply, $this->snapshot($reader));
        Http::assertNothingSent();
    }

    public static function concurrentChanges(): array
    {
        return ['closed period' => ['period'], 'changed fact' => ['fact'], 'new draft statement' => ['statement']];
    }

    private function snapshot(Connection $connection): array
    {
        return collect(['daily_reports', 'report_dimensions', 'report_source_connections', 'financial_periods',
            'publisher_statements', 'report_import_jobs', 'reconciliation_runs', 'gam_revenue_corrections',
            'gam_revenue_correction_receipts'])
            ->mapWithKeys(fn ($table) => [$table => $connection->table($table)->orderBy('id')->get()->toJson()])->all();
    }

    private function committedFixture(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00 UTC'));
        Http::preventStrayRequests();
        Http::fake();
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser);
        $site = $this->makeSiteFor($publisher, $publisherUser, ['primary_domain' => 'concurrency.test.example']);
        $gam = $this->makeGamConnection($admin->organization, $admin, ['network_code' => '123']);
        $source = ReportSource::query()->create(['code' => 'GAM_AD_UNIT', 'name' => 'Synthetic GAM concurrency source', 'is_enabled' => true]);
        $bindingId = (string) Str::ulid();
        $connection = ReportSourceConnection::withoutGlobalScopes()->create([
            'organization_id' => $publisher->organization_id, 'report_source_id' => $source->id,
            'name' => 'Synthetic concurrency source', 'connection_type' => 'SITE_GAM_AD_UNIT', 'connection_id' => $bindingId,
            'currency' => 'USD', 'timezone' => 'UTC', 'status' => 'ACTIVE', 'is_enabled' => true,
            'configuration' => ['google_jobs' => ['synthetic-forward-job' => ['id' => '999']]],
        ]);
        $binding = SiteGamReportBinding::withoutGlobalScopes()->create([
            'id' => $bindingId, 'organization_id' => $publisher->organization_id, 'site_id' => $site->id,
            'gam_connection_id' => $gam->id, 'report_source_connection_id' => $connection->id,
            'active_site_id' => $site->id, 'active_unit_key' => '123:456', 'network_code' => '123',
            'ad_unit_id' => '456', 'ad_unit_name' => 'Synthetic unit', 'ad_unit_code' => 'synthetic', 'starts_on' => '2026-09-01',
        ]);
        $job = ReportImportJob::withoutGlobalScopes()->create([
            'organization_id' => $publisher->organization_id, 'report_source_connection_id' => $connection->id,
            'import_type' => 'API', 'granularity' => 'DAILY', 'finality' => 'FINALIZED', 'status' => 'COMPLETED',
            'period_start' => '2026-09-01', 'period_end' => '2026-09-01', 'idempotency_key' => hash('sha256', 'synthetic-concurrent-import'),
        ]);
        $dimension = app(ReportDimensionResolver::class)->resolve(['site_id' => $site->id, 'gam_connection_id' => $gam->id, 'gam_ad_unit_id' => '456']);
        $period = FinancialPeriod::create(['period_key' => '2026-09', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'currency' => 'USD', 'status' => 'OPEN']);
        $rule = RevenueRule::withoutGlobalScopes()->create(['organization_id' => $publisher->organization_id, 'name' => 'Synthetic original rule', 'scope_type' => 'WEBSITE', 'scope_id' => $site->id, 'is_active' => true, 'effective_from' => '2026-09-01']);
        $version = RevenueRuleVersion::create(['revenue_rule_id' => $rule->id, 'version' => 1, 'publisher_share_bp' => 7000, 'horus_share_bp' => 2500, 'mcm_partner_share_bp' => 500, 'effective_from' => '2026-09-01', 'currency' => 'USD']);
        $rule->update(['current_version_id' => $version->id]);
        $fact = DailyReport::withoutGlobalScopes()->create([
            'organization_id' => $publisher->organization_id, 'report_source_connection_id' => $connection->id,
            'report_import_job_id' => $job->id, 'report_dimension_id' => $dimension->id, 'report_date' => '2026-09-01',
            'currency' => 'USD', 'finality' => 'FINALIZED', 'settlement_eligible' => true,
            'financial_period_id' => $period->id, 'revenue_rule_version_id' => $version->id,
            'gross_revenue_minor' => 123, 'demand_partner_deductions_minor' => 10, 'net_revenue_minor' => 113,
            'publisher_earnings_minor' => 79, 'horus_earnings_minor' => 29, 'mcm_partner_earnings_minor' => 5,
            'impressions' => 25, 'source_row_hash' => hash('sha256', 'synthetic-concurrent-row'),
        ]);
        app(SiteGamReportScope::class)->ensure($binding);
        $this->app->instance(GamAdUnitReportClient::class, new class extends GamAdUnitReportClient
        {
            public function __construct() {}
            public function call(GamConnection $connection, string $service, string $method, array $payload = []): array
            {
                return match ($method) {
                    'getCurrentNetwork' => ['networkCode' => '123', 'timeZone' => 'UTC', 'currencyCode' => 'USD'],
                    'runReportJob' => ['id' => '1', 'reportQuery' => ['reportCurrency' => 'USD']],
                    'getReportJobStatus' => ['value' => 'COMPLETED'],
                    default => throw new \RuntimeException('Unexpected synthetic Google operation: '.$method),
                };
            }
            public function download(GamConnection $connection, string $jobId): string
            {
                return implode(',', ['Dimension.DATE', 'Dimension.AD_UNIT_ID', 'Dimension.SITE_NAME',
                    ...array_map(fn ($column) => 'Column.'.$column, array_keys(SiteGamReportMetrics::CORE_COLUMNS))])."\n"
                    ."2026-09-01,456,concurrency.test.example,40,30,20,2,USD 2005000\n";
            }
        });
        $this->actingAs($admin);

        return compact('admin', 'publisher', 'site', 'period', 'fact');
    }
}
