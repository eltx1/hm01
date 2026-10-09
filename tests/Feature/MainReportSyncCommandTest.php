<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\DailyReport;
use App\Models\GamApiOperation;
use App\Models\GamConnection;
use App\Models\ReportImportJob;
use App\Models\SiteGamReportBinding;
use App\Services\Gam\Contracts\GamSoapTransportInterface;
use App\Services\Reporting\GamAdUnitReportClient;
use App\Services\Reporting\MainReportSyncLock;
use App\Services\Reporting\MainReportSyncLockException;
use App\Services\Reporting\SiteGamReportingService;
use App\Services\Reporting\SiteGamReportMetrics;
use App\Services\Reporting\SiteGamUnfilledSynchronizer;
use Carbon\CarbonImmutable;
use Database\Seeders\InventoryDeliverySeeder;
use Database\Seeders\ReportingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

/** Command/provider wiring uses a test double; production has no SQLite fallback. */
class MainReportSyncCommandTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));
    }

    public function test_contended_command_skips_before_querying_bindings_or_calling_google(): void
    {
        $lock = $this->mockLock();
        $lock->shouldReceive('acquire')->once()->andReturn(false);
        $lock->shouldNotReceive('assertOwned');
        $lock->shouldNotReceive('release');
        $transport = Mockery::mock(GamSoapTransportInterface::class);
        $transport->shouldNotReceive('call');
        $this->app->instance(GamSoapTransportInterface::class, $transport);
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void { $queries[] = $event->sql; });

        $this->assertSame(0, Artisan::call('reporting:sync-site-gam', ['--site' => 'synthetic-site']));
        $this->assertStringContainsString('already running', Artisan::output());
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    public function test_healthy_empty_command_checks_ownership_and_releases_once(): void
    {
        $lock = $this->mockLock();
        $lock->shouldReceive('acquire')->once()->andReturn(true);
        $lock->shouldReceive('assertOwned')->once()->andReturnNull();
        $lock->shouldReceive('release')->once()->andReturnNull();

        $this->assertSame(0, Artisan::call('reporting:sync-site-gam'));
        $this->assertDatabaseCount('report_import_jobs', 0);
        $this->assertDatabaseCount('gam_api_operations', 0);
        Http::assertNothingSent();
    }

    public function test_acquisition_failure_returns_failure_without_querying_bindings(): void
    {
        $lock = $this->mockLock();
        $lock->shouldReceive('acquire')->once()->andThrow(new MainReportSyncLockException('Synthetic acquisition failure'));
        // Failed acquisition owns its own partial-acquisition cleanup.
        $lock->shouldNotReceive('release');
        $lock->shouldNotReceive('assertOwned');
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void { $queries[] = $event->sql; });

        $this->assertSame(1, Artisan::call('reporting:sync-site-gam'));
        $this->assertStringContainsString('Synthetic acquisition failure', Artisan::output());
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    public function test_guard_failure_releases_and_a_later_invocation_can_succeed(): void
    {
        $lock = $this->mockLock();
        $checks = 0;
        $lock->shouldReceive('acquire')->twice()->andReturn(true);
        $lock->shouldReceive('assertOwned')->twice()->andReturnUsing(static function () use (&$checks): void {
            if (++$checks === 1) throw new MainReportSyncLockException('Synthetic ownership loss');
        });
        $lock->shouldReceive('release')->twice()->andReturnNull();

        $this->assertSame(1, Artisan::call('reporting:sync-site-gam'));
        $this->assertStringContainsString('Synthetic ownership loss', Artisan::output());
        $this->assertSame(0, Artisan::call('reporting:sync-site-gam'));
        $this->assertSame(2, $checks);
        $this->assertDatabaseCount('daily_reports', 0);
        Http::assertNothingSent();
    }

    public function test_cleanup_failure_cannot_be_reported_as_a_successful_command(): void
    {
        $lock = $this->mockLock();
        $lock->shouldReceive('acquire')->once()->andReturn(true);
        $lock->shouldReceive('assertOwned')->once()->andReturnNull();
        $lock->shouldReceive('release')->once()->andThrow(new MainReportSyncLockException('Synthetic cleanup failure'));

        $this->assertSame(1, Artisan::call('reporting:sync-site-gam'));
        $this->assertStringContainsString('Synthetic cleanup failure', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_provider_entry_guard_stops_before_audit_rows_or_soap_calls(): void
    {
        $lock = $this->mockLock();
        $lock->shouldReceive('assertOwned')->once()->andThrow(new MainReportSyncLockException('Synthetic ownership loss'));
        $transport = Mockery::mock(GamSoapTransportInterface::class);
        $transport->shouldNotReceive('call');
        $this->app->instance(GamSoapTransportInterface::class, $transport);

        try {
            app(GamAdUnitReportClient::class)->call(new GamConnection, 'NetworkService', 'getCurrentNetwork');
            $this->fail('Provider work must not start after ownership loss.');
        } catch (MainReportSyncLockException $exception) {
            $this->assertSame('Synthetic ownership loss', $exception->getMessage());
        }
        $this->assertDatabaseCount('gam_api_operations', 0);
        Http::assertNothingSent();
    }

    public function test_late_provider_response_aborts_unfilled_and_later_bindings_without_failure_writes(): void
    {
        [$state, $google, $bindings] = $this->reportingFixture(2);
        $before = array_map(fn ($binding) => $binding->connection->fresh()->configuration, $bindings);
        $google->afterCall = static function (string $method) use ($state): void {
            if ($method === 'runReportJob') $state->lost = true;
        };

        $this->assertSame(1, Artisan::call('reporting:sync-site-gam'));
        $this->assertStringContainsString('Synthetic ownership loss', Artisan::output());
        $this->assertStringNotContainsString('Unit Unfilled:', Artisan::output());
        $this->assertSame(1, $state->releases);
        $this->assertFalse($state->active);
        $this->assertCount(1, $google->requests);
        $query = array_values($google->requests)[0];
        $this->assertContains('SITE_NAME', $query['dimensions']);
        $this->assertSame(0, ReportImportJob::withoutGlobalScopes()->count());
        $this->assertSame(0, GamApiOperation::withoutGlobalScopes()->where('status', 'FAILED')->count());
        $this->assertDatabaseCount('daily_reports', 0);
        $this->assertDatabaseCount('hourly_reports', 0);
        $this->assertDatabaseCount('site_gam_unfilled_reports', 0);
        foreach ($bindings as $index => $binding) {
            $this->assertSame($before[$index], $binding->connection->fresh()->configuration);
        }
        Http::assertNothingSent();
    }

    public function test_recovery_then_repeated_command_keeps_one_financial_snapshot(): void
    {
        [$state, $google, $bindings] = $this->reportingFixture();
        $google->afterCall = static function (string $method) use ($state): void {
            if ($method === 'runReportJob') $state->lost = true;
        };
        $this->assertSame(1, Artisan::call('reporting:sync-site-gam'));
        $this->assertDatabaseCount('daily_reports', 0);

        $google->afterCall = null;
        $this->assertSame(0, Artisan::call('reporting:sync-site-gam'));
        $this->assertStringContainsString('Unit Unfilled: COMPLETED', Artisan::output());
        $row = DailyReport::withoutGlobalScopes()->where('report_source_connection_id', $bindings[0]->report_source_connection_id)->sole();
        $snapshot = $row->getAttributes();
        $this->assertSame(100, (int) $row->gross_revenue_minor);
        $this->assertSame(100, (int) $row->net_revenue_minor);
        $this->assertSame(70, (int) $row->publisher_earnings_minor);
        $this->assertSame(30, (int) $row->horus_earnings_minor);
        $this->assertSame(0, (int) $row->mcm_partner_earnings_minor);
        $this->assertSame(1, (int) $row->revision);
        $requests = count($google->requests);

        $this->assertSame(0, Artisan::call('reporting:sync-site-gam'));
        $this->assertSame($snapshot, $row->fresh()->getAttributes());
        $this->assertSame($requests, count($google->requests));
        $this->assertDatabaseCount('daily_reports', 1);
        $this->assertDatabaseCount('hourly_reports', 0);
        $this->assertDatabaseCount('site_gam_unfilled_reports', 1);
        $this->assertSame(3, $state->acquisitions);
        $this->assertSame(3, $state->releases);
    }

    public function test_ordinary_provider_failure_still_allows_independent_unfilled_reporting(): void
    {
        [$state, $google] = $this->reportingFixture();
        $google->failFinanceStatus = true;

        $this->assertSame(1, Artisan::call('reporting:sync-site-gam'));
        $this->assertStringContainsString('Unit Unfilled: COMPLETED', Artisan::output());
        $this->assertFalse($state->lost);
        $this->assertSame(1, $state->releases);
        $this->assertGreaterThan(0, ReportImportJob::withoutGlobalScopes()->where('status', 'FAILED')->count());
        $this->assertDatabaseCount('daily_reports', 0);
        $this->assertDatabaseCount('site_gam_unfilled_reports', 1);
    }

    public function test_ownership_loss_during_download_preserves_the_guard_exception(): void
    {
        [$state, $google, $bindings] = $this->reportingFixture();
        $google->afterDownload = static function () use ($state) {
            $state->lost = true;
            return Http::response('synthetic CSV');
        };
        $state->active = true;
        try {
            app(GamAdUnitReportClient::class)->download($bindings[0]->gamConnection, '999');
            $this->fail('A late download must not be returned after ownership loss.');
        } catch (MainReportSyncLockException $exception) {
            $this->assertSame('Synthetic ownership loss', $exception->getMessage());
            $this->assertStringNotContainsString('signature', $exception->getMessage());
        } finally {
            $state->active = false;
        }
        Http::assertSentCount(1);
        $this->assertDatabaseCount('report_import_jobs', 0);
        $this->assertDatabaseCount('daily_reports', 0);
    }

    private function mockLock(): MainReportSyncLock
    {
        $lock = Mockery::mock(MainReportSyncLock::class);
        $this->app->instance(MainReportSyncLock::class, $lock);
        return $lock;
    }

    /** Exercise real reporting services; replace only ownership and external Google I/O. */
    private function reportingFixture(int $count = 1): array
    {
        $state = (object) ['active' => false, 'lost' => false, 'acquisitions' => 0, 'releases' => 0];
        $lock = $this->mockLock();
        $lock->shouldReceive('acquire')->andReturnUsing(static function () use ($state): bool {
            $state->active = true;
            $state->lost = false;
            $state->acquisitions++;
            return true;
        });
        $lock->shouldReceive('assertOwned')->andReturnUsing(static function () use ($state): void {
            if ($state->active && $state->lost) throw new MainReportSyncLockException('Synthetic ownership loss');
        });
        $lock->shouldReceive('release')->andReturnUsing(static function () use ($state): void {
            $state->active = false;
            $state->releases++;
        });
        // Simulate the real guard's fail-closed query hook without pretending
        // that SQLite supplies cross-process session locks.
        DB::connection()->beforeExecuting(static fn () => $lock->assertOwned());

        $this->seedIdentity();
        config(['reporting.default_publisher_share_bp' => 7000,
            'reporting.default_horus_share_bp' => 3000, 'reporting.default_mcm_share_bp' => 0]);
        $this->seed([InventoryDeliverySeeder::class, ReportingSeeder::class]);
        $horus = $this->makeOrganization(OrganizationType::HorusMedia);
        $admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $organization = $this->makeOrganization(OrganizationType::Publisher);
        $user = $this->makeUser($organization, RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($user);
        $gam = $this->makeGamConnection($horus, $admin, ['network_code' => '987654321']);
        $google = new class implements GamSoapTransportInterface
        {
            public string $unit = '41001';
            public array $calls = [];
            public array $requests = [];
            public array $hostnames = [];
            public mixed $afterCall = null;
            public mixed $afterDownload = null;
            public bool $failFinanceStatus = false;

            public function call(GamConnection $connection, string $service, string $method, array $payload = []): array
            {
                $this->calls[] = compact('service', 'method', 'payload');
                if ($method === 'runReportJob') {
                    $id = (string) (count($this->requests) + 1);
                    $this->requests[$id] = $payload['reportJob']['reportQuery'];
                    $result = ['id' => $id, 'reportQuery' => ['reportCurrency' => 'USD']];
                } else {
                    if ($method === 'getReportJobStatus' && $this->failFinanceStatus
                        && in_array('SITE_NAME', $this->requests[$payload['reportJobId']]['dimensions'], true)) {
                        throw new \RuntimeException('Synthetic ordinary provider failure');
                    }
                    $result = match ($method) {
                        'getCurrentNetwork' => ['networkCode' => $connection->network_code, 'currencyCode' => 'USD', 'timeZone' => 'UTC'],
                        'getAdUnitsByStatement' => ['results' => [['id' => $this->unit, 'name' => 'Synthetic unit', 'adUnitCode' => 'synthetic_'.$this->unit]]],
                        'getReportJobStatus' => ['value' => 'COMPLETED'],
                        'getReportDownloadUrlWithOptions' => ['value' => 'https://storage.googleapis.com/main-sync-'.$payload['reportJobId'].'.csv?signature=synthetic-private'],
                        default => throw new \RuntimeException('Unexpected synthetic Google call: '.$method),
                    };
                }
                if ($this->afterCall !== null) ($this->afterCall)($method);
                return $result;
            }
        };
        $this->app->instance(GamSoapTransportInterface::class, $google);
        Http::fake(['storage.googleapis.com/*' => static function ($request) use ($google) {
            if ($google->afterDownload !== null) return ($google->afterDownload)();
            preg_match('/main-sync-(\d+)\.csv/', $request->url(), $matches);
            $query = $google->requests[$matches[1]];
            $unit = (string) $query['statement']['values'][0]['value']['value'];
            $day = sprintf('%04d-%02d-%02d', $query['startDate']['year'], $query['startDate']['month'], $query['startDate']['day']);
            $stream = fopen('php://temp', 'w+');
            if ($query['columns'] === [SiteGamUnfilledSynchronizer::COLUMN]) {
                fputcsv($stream, ['Dimension.DATE', 'Dimension.AD_UNIT_ID', 'Column.'.SiteGamUnfilledSynchronizer::COLUMN], escape: '');
                fputcsv($stream, [$day, $unit, 7], escape: '');
            } else {
                fputcsv($stream, ['Dimension.DATE', 'Dimension.AD_UNIT_ID', 'Dimension.SITE_NAME',
                    ...array_map(static fn ($column) => 'Column.'.$column, array_keys(SiteGamReportMetrics::COLUMNS))], escape: '');
                fputcsv($stream, [$day, $unit, $google->hostnames[$unit], 120, 100, 95, 3, 'US$ 1000000', 40, 80], escape: '');
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            fclose($stream);
            return Http::response($csv);
        }]);
        $bindings = [];
        for ($index = 0; $index < $count; $index++) {
            $google->unit = (string) (41001 + $index);
            $hostname = 'main-sync-'.$index.'.example.test';
            $google->hostnames[$google->unit] = $hostname;
            $site = $this->makeSiteFor($publisher, $user, ['primary_domain' => $hostname]);
            $bindings[] = app(SiteGamReportingService::class)->bind($site, $gam->id, $google->unit, $admin);
        }
        $google->calls = [];
        return [$state, $google, $bindings];
    }
}
