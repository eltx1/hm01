<?php

namespace Tests\Feature;

use App\Enums\DemandAccountScope;
use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Enums\FinancialReportingMethod;
use App\Enums\OrganizationType;
use App\Enums\ReportFinality;
use App\Enums\ReportGranularity;
use App\Enums\ReportSourceCode;
use App\Enums\RoleName;
use App\Models\BidderAccount;
use App\Models\DailyReport;
use App\Models\DemandAccount;
use App\Models\DemandNetwork;
use App\Models\DemandSite;
use App\Models\FinancialPeriod;
use App\Models\PrebidBidder;
use App\Models\PublisherStatement;
use App\Models\ReportSource;
use App\Models\ReportSourceConnection;
use App\Services\Demand\DemandAccountService;
use App\Services\Prebid\PrebidManager;
use App\Services\Reporting\FinancialPeriodService;
use App\Services\Reporting\MonetizationFinancialBindingService;
use App\Services\Reporting\MonetizationFinancialReadinessService;
use App\Services\Reporting\ReportImportService;
use Database\Seeders\DemandNetworkSeeder;
use Database\Seeders\InventoryDeliverySeeder;
use Database\Seeders\PrebidSeeder;
use Database\Seeders\ReportingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

final class ProviderFinancialSourceIntegrityTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private $admin;

    private $publisher;

    private $publisherUser;

    private $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, DemandNetworkSeeder::class, PrebidSeeder::class, ReportingSeeder::class]);
        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $this->admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $publisherOrg = $this->makeOrganization(OrganizationType::Publisher, 'Financial Source Publisher');
        $this->publisherUser = $this->makeUser($publisherOrg, RoleName::PublisherAdmin);
        $this->publisher = $this->makePublisherFor($this->publisherUser, ['display_name' => 'Financial Source Publisher']);
        $this->site = $this->makeSiteFor($this->publisher, $this->publisherUser, ['display_name' => 'Financial Source Site']);
    }

    public function test_exoclick_and_onetag_are_first_class_canonical_sources_and_api_is_not_invented(): void
    {
        $this->assertDatabaseHas('report_sources', ['code' => 'EXOCLICK', 'name' => 'ExoClick']);
        $this->assertDatabaseHas('report_sources', ['code' => 'ONETAG', 'name' => 'OneTag']);

        $exoclick = $this->demandAccount('EXOCLICK', []);
        $bindings = app(MonetizationFinancialBindingService::class);
        $api = $bindings->bind(
            $exoclick,
            ReportSource::query()->where('code', ReportSourceCode::ExoClick->value)->firstOrFail(),
            FinancialReportingMethod::Api,
            'USD',
            'UTC',
            $this->admin,
        );
        $this->assertFalse($api->is_finalized_capable);
        $this->assertSame('NOT_CONFIGURED', app(MonetizationFinancialReadinessService::class)->status($exoclick)['status']);

        $csv = $bindings->bind(
            $exoclick,
            ReportSource::query()->where('code', ReportSourceCode::ExoClick->value)->firstOrFail(),
            FinancialReportingMethod::Csv,
            'USD',
            'UTC',
            $this->admin,
            ['operator_note' => 'Approved provider export'],
        );
        $this->assertTrue($csv->is_finalized_capable);
        $this->assertSame('EXOCLICK', $csv->source->code->value);

        $oneTag = $this->oneTagAccount();
        $oneTagBinding = $bindings->bind(
            $oneTag,
            ReportSource::query()->where('code', ReportSourceCode::OneTag->value)->firstOrFail(),
            FinancialReportingMethod::Csv,
            'USD',
            'UTC',
            $this->admin,
        );
        $this->assertTrue($oneTagBinding->is_finalized_capable);
        $this->assertSame('BIDDER_ACCOUNT', $oneTagBinding->subject_type->value);
    }

    public function test_existing_horus_mcm_and_publisher_gam_sources_remain_finalized_capable(): void
    {
        $date = now()->subMonthNoOverflow()->startOfMonth()->addDay()->toImmutable();
        foreach ([
            ReportSourceCode::HorusGam,
            ReportSourceCode::McmPartnerGam,
            ReportSourceCode::PublisherGam,
        ] as $offset => $sourceCode) {
            $rowDate = $date->addDays($offset);
            $job = app(ReportImportService::class)->importRows(
                $this->connection($sourceCode, 'GAM_CONNECTION'),
                [$this->row($rowDate)],
                ReportGranularity::Daily,
                ReportFinality::Finalized,
                $rowDate,
                $rowDate,
                $this->admin,
                'gam-regression-'.$sourceCode->value,
            );
            $this->assertTrue($job->settlement_eligible, $sourceCode->value.' must remain financially finalized capable.');
            $this->assertSame(ReportFinality::Finalized, $job->finality);
        }
    }

    public function test_prebid_estimates_and_onetag_estimate_mode_cannot_become_payout_ready(): void
    {
        $account = $this->oneTagAccount();
        $bindings = app(MonetizationFinancialBindingService::class);
        $date = now()->subMonthNoOverflow()->startOfMonth()->addDay()->toImmutable();
        $oneTagEstimate = $bindings->bind(
            $account,
            ReportSource::query()->where('code', ReportSourceCode::OneTag->value)->firstOrFail(),
            FinancialReportingMethod::Estimate,
            'USD',
            'UTC',
            $this->admin,
        );
        $oneTagJob = app(ReportImportService::class)->importRows(
            $oneTagEstimate->connection,
            [$this->row($date)],
            ReportGranularity::Daily,
            ReportFinality::Finalized,
            $date,
            $date,
            $this->admin,
            'adversarial-onetag-estimate-finalized-claim',
            importType: 'PREBID_ESTIMATE',
        );
        $this->assertFalse($oneTagJob->settlement_eligible);
        $this->assertSame('REPORTING_METHOD_NOT_FINALIZED_CAPABLE', $oneTagJob->settlement_ineligibility_reason);
        $this->assertSame('ESTIMATE_ONLY', app(MonetizationFinancialReadinessService::class)->status($account)['status']);

        $binding = $bindings->bind(
            $account,
            ReportSource::query()->where('code', ReportSourceCode::PrebidEstimates->value)->firstOrFail(),
            FinancialReportingMethod::Estimate,
            'USD',
            'UTC',
            $this->admin,
        );
        $date = $date->addDay();
        $job = app(ReportImportService::class)->importRows(
            $binding->connection,
            [$this->row($date)],
            ReportGranularity::Daily,
            ReportFinality::Finalized,
            $date,
            $date,
            $this->admin,
            'adversarial-prebid-finalized-claim',
            importType: 'PREBID_ESTIMATE',
        );

        $this->assertSame(ReportFinality::Estimated, $job->finality);
        $this->assertFalse($job->settlement_eligible);
        $this->assertSame('PREBID_ESTIMATES_NEVER_SETTLEMENT_ELIGIBLE', $job->settlement_ineligibility_reason);
        $this->assertDatabaseHas('daily_reports', [
            'report_import_job_id' => $job->id,
            'finality' => 'ESTIMATED',
            'settlement_eligible' => false,
        ]);
        $template = DailyReport::withoutGlobalScopes()->where('report_import_job_id', $job->id)->firstOrFail();
        $directBypass = DailyReport::withoutGlobalScopes()->create([
            'organization_id' => $template->organization_id,
            'report_source_connection_id' => $template->report_source_connection_id,
            'report_import_job_id' => $template->report_import_job_id,
            'financial_period_id' => $template->financial_period_id,
            'report_dimension_id' => $template->report_dimension_id,
            'report_date' => $date->addDay(),
            'finality' => ReportFinality::Finalized,
            'currency' => 'USD',
            'source_row_hash' => hash('sha256', 'direct-finalized-bypass-attempt'),
        ]);
        $this->assertFalse($directBypass->fresh()->settlement_eligible, 'Database defaults must fail closed outside the canonical import service.');
        $period = FinancialPeriod::query()->where('period_key', $date->format('Y-m'))->firstOrFail();
        $readiness = app(FinancialPeriodService::class)->readiness($period);
        $this->assertFalse($readiness['ready']);
        $this->assertContains('MISSING_FINALIZED_DATA', collect($readiness['blockers'])->pluck('code'));
        $this->assertDatabaseMissing('monthly_reports', ['financial_period_id' => $period->id]);
        $this->assertDatabaseMissing('publisher_statements', ['financial_period_id' => $period->id]);
    }

    public function test_approved_provider_csv_is_idempotent_reconciled_and_payout_eligible(): void
    {
        Storage::fake('local');
        $account = $this->oneTagAccount();
        $binding = app(MonetizationFinancialBindingService::class)->bind(
            $account,
            ReportSource::query()->where('code', ReportSourceCode::OneTag->value)->firstOrFail(),
            FinancialReportingMethod::Csv,
            'USD',
            'UTC',
            $this->admin,
        );
        $date = now()->subMonthNoOverflow()->startOfMonth()->addDay()->toImmutable();
        $csv = implode(',', ['date', 'publisher_id', 'site_id', 'impressions', 'gross_revenue_minor', 'currency'])."\n"
            .implode(',', [$date->toDateString(), $this->publisher->id, $this->site->id, 1000, 10000, 'USD'])."\n";
        $imports = app(ReportImportService::class);
        $first = $imports->importCsv(
            $binding->connection,
            UploadedFile::fake()->createWithContent('onetag.csv', $csv),
            ReportGranularity::Daily,
            ReportFinality::Finalized,
            $date,
            $date,
            $this->admin,
        );
        $second = $imports->importCsv(
            $binding->connection,
            UploadedFile::fake()->createWithContent('onetag-duplicate.csv', $csv),
            ReportGranularity::Daily,
            ReportFinality::Finalized,
            $date,
            $date,
            $this->admin,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertTrue($first->settlement_eligible);
        $this->assertSame(ReportFinality::Finalized, $first->finality);
        $this->assertSame('MATCHED', $first->reconciliations()->firstOrFail()->status->value);
        $this->assertSame('READY', app(MonetizationFinancialReadinessService::class)->status(
            $account,
            'USD',
            FinancialPeriod::query()->where('period_key', $date->format('Y-m'))->firstOrFail(),
        )['status']);
        $this->assertSame(1, DailyReport::withoutGlobalScopes()->where('settlement_eligible', true)->count());
    }

    public function test_readiness_surfaces_missing_currency_failed_and_stale_states(): void
    {
        $service = app(MonetizationFinancialReadinessService::class);
        $missing = $this->oneTagAccount('OneTag Missing');
        $this->assertSame('NOT_CONFIGURED', $service->status($missing)['status']);

        $account = $this->oneTagAccount('OneTag States');
        $binding = app(MonetizationFinancialBindingService::class)->bind(
            $account,
            ReportSource::query()->where('code', ReportSourceCode::OneTag->value)->firstOrFail(),
            FinancialReportingMethod::Csv,
            'USD',
            'UTC',
            $this->admin,
        );
        $this->assertSame('CURRENCY_MISMATCH', $service->status($account, 'EUR')['status']);
        $currencyDate = now()->subMonthNoOverflow()->startOfMonth()->addDays(3)->toImmutable();
        $currencyRow = $this->row($currencyDate);
        $currencyRow['currency'] = 'EUR';
        $currencyJob = app(ReportImportService::class)->importRows(
            $binding->connection,
            [$currencyRow],
            ReportGranularity::Daily,
            ReportFinality::Finalized,
            $currencyDate,
            $currencyDate,
            $this->admin,
            'currency-mismatch-attempt',
            importType: 'CSV',
        );
        $this->assertSame('FAILED', $currencyJob->status->value);
        $this->assertFalse($currencyJob->settlement_eligible);
        $binding->connection->update(['status' => 'ERROR']);
        $this->assertSame('FAILED', $service->status($account)['status']);

        $date = now()->subDays(10)->startOfDay()->toImmutable();
        $binding->connection->update(['status' => 'ACTIVE']);
        app(ReportImportService::class)->importRows(
            $binding->connection,
            [$this->row($date)],
            ReportGranularity::Daily,
            ReportFinality::Finalized,
            $date,
            $date,
            $this->admin,
            'stale-provider-data',
            importType: 'CSV',
        );
        $binding->connection->update([
            'last_successful_import_at' => now()->subDays(10),
            'last_finalized_import_at' => now()->subDays(10),
        ]);
        $this->assertSame('STALE', $service->status($account->fresh())['status']);
    }

    public function test_financial_close_blocks_unconfigured_production_account_and_existing_override_is_permissioned_reasoned_and_audited(): void
    {
        $account = $this->oneTagAccount('OneTag Production');
        app(PrebidManager::class)->assignToSite($account, $this->site, [
            'public_parameters' => ['pubId' => 'TEST_ONLY_PUB_ID'],
            'enabled' => true,
            'sequence' => 1,
        ], $this->admin);
        $date = now()->subMonthNoOverflow()->startOfMonth()->addDay()->toImmutable();
        BidderAccount::withoutGlobalScopes()->whereKey($account->id)->update(['created_at' => $date->copy()->subDay()]);
        $connection = $this->connection(ReportSourceCode::HorusGam, 'GAM_CONNECTION');
        app(ReportImportService::class)->importRows(
            $connection,
            [$this->row($date)],
            ReportGranularity::Daily,
            ReportFinality::Finalized,
            $date,
            $date,
            $this->admin,
            'gam-finalized-control',
        );
        $period = FinancialPeriod::query()->where('period_key', $date->format('Y-m'))->firstOrFail();
        $readiness = app(FinancialPeriodService::class)->readiness($period);
        $coverage = collect($readiness['blockers'])->firstWhere('code', 'MONETIZATION_SOURCE_COVERAGE');
        $this->assertSame(1, $coverage['count']);
        $this->assertSame('NOT_CONFIGURED', data_get($coverage, 'subjects.0.status'));

        $finance = $this->makeUser($this->admin->organization, RoleName::FinanceAdmin);
        try {
            app(FinancialPeriodService::class)->close($period, $finance, 'Override missing provider source for a documented exception');
            $this->fail('Finance Admin must not inherit the exceptional period override permission.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        app(FinancialPeriodService::class)->close(
            $period->fresh(),
            $this->admin,
            'Documented exceptional close while provider reporting is remediated',
        );
        $this->assertDatabaseHas('audit_logs', ['event' => 'finance.financial_period.close_overridden', 'auditable_id' => $period->id]);
        $this->assertDatabaseHas('publisher_statements', ['financial_period_id' => $period->id, 'publisher_id' => $this->publisher->id]);
        $this->assertSame(7000, PublisherStatement::withoutGlobalScopes()->where('financial_period_id', $period->id)->firstOrFail()->publisher_earnings_minor);
    }

    public function test_manual_provider_finality_requires_finance_reason_and_explicit_manual_binding(): void
    {
        $account = $this->demandAccount('EXOCLICK', []);
        $source = ReportSource::query()->where('code', ReportSourceCode::ExoClick->value)->firstOrFail();
        $binding = app(MonetizationFinancialBindingService::class)->bind(
            $account, $source, FinancialReportingMethod::Manual, 'USD', 'UTC', $this->admin,
        );
        $date = now()->subMonthNoOverflow()->startOfMonth()->addDays(2)->toImmutable();

        try {
            app(ReportImportService::class)->importRows(
                $binding->connection,
                [$this->row($date)],
                ReportGranularity::Daily,
                ReportFinality::Finalized,
                $date,
                $date,
                $this->admin,
                'manual-without-evidence',
                importType: 'MANUAL',
            );
            $this->fail('Manual finalized revenue must require Finance evidence.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('manual_reason', $exception->errors());
        }

        $job = app(ReportImportService::class)->importRows(
            $binding->connection,
            [$this->row($date)],
            ReportGranularity::Daily,
            ReportFinality::Finalized,
            $date,
            $date,
            $this->admin,
            'manual-with-finance-evidence',
            importType: 'MANUAL',
            manualReason: 'Provider portal outage; Finance approved this signed monthly report.',
        );
        $this->assertTrue($job->settlement_eligible);
        $this->assertDatabaseHas('audit_logs', ['event' => 'reporting.manual_import.completed', 'auditable_id' => $job->id]);
    }

    public function test_financial_source_write_is_horus_only_secrets_are_rejected_and_no_browser_telemetry_route_exists(): void
    {
        $account = $this->oneTagAccount();
        $source = ReportSource::query()->where('code', ReportSourceCode::OneTag->value)->firstOrFail();
        $this->actingAs($this->publisherUser)->put(route('admin.prebid.accounts.financial-source', $account), [
            'report_source_id' => $source->id,
            'reporting_method' => 'CSV',
            'currency' => 'USD',
            'timezone' => 'UTC',
            'is_enabled' => true,
            'configuration_json' => '{}',
        ])->assertNotFound();

        try {
            app(MonetizationFinancialBindingService::class)->bind(
                $account,
                $source,
                FinancialReportingMethod::Csv,
                'USD',
                'UTC',
                $this->admin,
                ['nested' => ['api_token' => 'must-never-be-stored']],
            );
            $this->fail('Sensitive configuration must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('configuration', $exception->errors());
        }
        $this->assertDatabaseMissing('monetization_financial_bindings', ['subject_id' => $account->id]);
        $uris = collect(Route::getRoutes())->map(fn ($route) => $route->uri())->implode("\n");
        $this->assertDoesNotMatchRegularExpression('/prebid\/(raw-?bid|auction|impression|telemetry)/i', $uris);
    }

    private function demandAccount(string $networkCode, array $configuration): DemandAccount
    {
        $network = DemandNetwork::query()->where('code', $networkCode)->firstOrFail();
        $account = app(DemandAccountService::class)->create([
            'organization_id' => $this->admin->organization_id,
            'demand_network_id' => $network->id,
            'name' => $network->name.' Finance',
            'scope' => DemandAccountScope::HorusMedia,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'is_default' => false,
            'revenue_share_percent' => 0,
            'fallback_priority' => 10,
            'account_identifier' => 'TEST_ONLY_PROVIDER_ACCOUNT',
            'configuration' => $configuration,
        ], $this->admin);
        DemandSite::withoutGlobalScopes()->create([
            'organization_id' => $this->site->organization_id,
            'demand_account_id' => $account->id,
            'site_id' => $this->site->id,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'is_default' => true,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'fallback_priority' => 10,
            'sync_status' => 'IN_SYNC',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        return $account;
    }

    private function oneTagAccount(string $name = 'OneTag Finance'): BidderAccount
    {
        return app(PrebidManager::class)->addAccount(
            PrebidBidder::query()->where('code', 'onetag')->firstOrFail(),
            [
                'name' => $name,
                'publisher_id' => 'TEST_ONLY_PUB_ID',
                'public_parameters' => ['pubId' => 'TEST_ONLY_PUB_ID'],
                'enabled' => true,
            ],
            $this->admin,
        );
    }

    private function connection(ReportSourceCode $code, string $type): ReportSourceConnection
    {
        return ReportSourceConnection::withoutGlobalScopes()->create([
            'organization_id' => $this->admin->organization_id,
            'report_source_id' => ReportSource::query()->where('code', $code->value)->firstOrFail()->id,
            'name' => $code->value.' Test',
            'connection_type' => $type,
            'connection_id' => strtolower($code->value).'-'.strtolower($type),
            'currency' => 'USD',
            'timezone' => 'UTC',
            'status' => 'ACTIVE',
            'is_enabled' => true,
        ]);
    }

    private function row($date): array
    {
        return [
            'date' => $date->toDateString(),
            'publisher_id' => $this->publisher->id,
            'site_id' => $this->site->id,
            'impressions' => 1000,
            'gross_revenue_minor' => 10000,
            'currency' => 'USD',
        ];
    }
}
