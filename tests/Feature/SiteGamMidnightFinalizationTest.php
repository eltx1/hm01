<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\ReportFinality;
use App\Enums\ReportGranularity;
use App\Enums\ReportImportStatus;
use App\Enums\RoleName;
use App\Models\DailyReport;
use App\Models\GamConnection;
use App\Models\ReportImportJob;
use App\Models\SiteGamReportBinding;
use App\Services\Gam\Contracts\GamSoapTransportInterface;
use App\Services\Reporting\ReportImportService;
use App\Services\Reporting\SiteGamReportingService;
use App\Services\Reporting\SiteGamReportMetrics;
use App\Services\Reporting\SiteGamReportSynchronizer;
use App\Services\Reporting\SiteGamVideoReportingService;
use Carbon\CarbonImmutable;
use Database\Seeders\InventoryDeliverySeeder;
use Database\Seeders\ReportingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class SiteGamMidnightFinalizationTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private object $google;

    private function makeReportBindings(): array
    {
        // Synthetic first-owned day. Network midnight precedes UTC midnight.
        $this->travelTo(CarbonImmutable::parse('2026-02-14 19:50:00', 'UTC'));
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, ReportingSeeder::class]);
        $horus = $this->makeOrganization(OrganizationType::HorusMedia);
        $admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $publisherOrg = $this->makeOrganization(OrganizationType::Publisher);
        $user = $this->makeUser($publisherOrg, RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($user), $user, ['primary_domain' => 'midnight.example.test']);
        $gam = $this->makeGamConnection($horus, $admin, ['network_code' => '987654321']);
        $this->google = new class implements GamSoapTransportInterface
        {
            public string $unit = '41001';
            public string $status = 'IN_PROGRESS';
            public array $statuses = [];
            public array $requests = [];
            public array $calls = [];
            public bool $failStatus = false;

            public function call(GamConnection $connection, string $service, string $method, array $payload = []): array
            {
                $this->calls[] = compact('method', 'payload');
                if ($method === 'getReportJobStatus' && $this->failStatus) {
                    throw new \RuntimeException('Synthetic temporary status failure');
                }
                if ($method === 'runReportJob') {
                    $id = (string) (count($this->requests) + 1);
                    $this->requests[$id] = $payload['reportJob']['reportQuery'];
                    return ['id' => $id, 'reportQuery' => ['reportCurrency' => 'USD']];
                }
                return match ($method) {
                    'getCurrentNetwork' => ['networkCode' => $connection->network_code, 'currencyCode' => 'USD', 'timeZone' => 'Asia/Dubai'],
                    'getAdUnitsByStatement' => ['results' => [['id' => $this->unit, 'name' => 'Synthetic unit', 'adUnitCode' => 'synthetic_unit']]],
                    'getReportJobStatus' => ['value' => $this->statuses[$payload['reportJobId']] ?? $this->status],
                    'getReportDownloadUrlWithOptions' => ['value' => 'https://storage.googleapis.com/midnight-report-'.$payload['reportJobId'].'.csv'],
                    default => throw new \RuntimeException('Unexpected synthetic Google call: '.$method),
                };
            }
        };
        $this->app->instance(GamSoapTransportInterface::class, $this->google);
        Http::preventStrayRequests();
        Http::fake(['storage.googleapis.com/*' => function ($request) {
            preg_match('/midnight-report-(\d+)\.csv$/', $request->url(), $match);
            $query = $this->google->requests[$match[1]];
            $date = $query['startDate'];
            $day = sprintf('%04d-%02d-%02d', $date['year'], $date['month'], $date['day']);
            $unit = $query['statement']['values'][0]['value']['value'];
            $stream = fopen('php://temp', 'w+');
            fputcsv($stream, ['Dimension.DATE', 'Dimension.AD_UNIT_ID', 'Dimension.SITE_NAME',
                ...array_map(fn ($column) => 'Column.'.$column, array_keys(SiteGamReportMetrics::COLUMNS))], escape: '');
            // Identical metrics for the estimated and separately requested final report.
            fputcsv($stream, [$day, $unit, 'midnight.example.test', 120, 100, 95, 3, 'US$ 1000000', 40, 80], escape: '');
            rewind($stream);
            $csv = stream_get_contents($stream);
            fclose($stream);
            return Http::response($csv);
        }]);
        $primary = app(SiteGamReportingService::class)->bind($site, $gam->id, $this->google->unit, $admin);
        $this->google->unit = '42002';
        $video = app(SiteGamVideoReportingService::class)->bind($site, $gam->id, $this->google->unit, $admin);
        $this->assertSame('2026-02-14', $video->starts_on->toDateString());
        return [$primary, $video];
    }

    private function sync(SiteGamReportBinding $binding): array
    {
        return app(SiteGamReportSynchronizer::class)->sync($binding->fresh());
    }

    private function row(SiteGamReportBinding $binding, string $day = '2026-02-14'): DailyReport
    {
        return DailyReport::withoutGlobalScopes()->where('report_source_connection_id', $binding->report_source_connection_id)
            ->whereDate('report_date', $day)->sole();
    }

    public function test_completed_prior_day_estimate_starts_a_fresh_final_report_without_six_hour_delay_or_primary_changes(): void
    {
        [$primary, $video] = $this->makeReportBindings();
        // Seed a separate primary fact before taking its unchanged-state snapshot.
        $this->google->status = 'COMPLETED';
        $day = CarbonImmutable::parse('2026-02-13', 'Asia/Dubai');
        $primaryJob = app(ReportImportService::class)->runConnection($primary->connection, $day, $day->endOfDay(),
            ReportGranularity::Daily, ReportFinality::Finalized);
        $this->assertSame(ReportImportStatus::Completed, $primaryJob->status, $primaryJob->error_message ?? '');
        $primaryBefore = $primary->fresh()->getAttributes();
        $connectionBefore = $primary->connection->fresh()->getAttributes();
        $primaryRowBefore = $this->row($primary, '2026-02-13')->getAttributes();
        $scopeBefore = $video->connection->configuration['site_report_scope'];

        $this->google->status = 'IN_PROGRESS';
        $initial = $this->sync($video);
        $this->assertCount(1, $initial);
        $this->assertSame(ReportFinality::Estimated, $initial[0]->finality);
        $this->assertSame(ReportImportStatus::Pending, $initial[0]->status);
        $oldGoogleId = (string) count($this->google->requests);
        $this->google->statuses[$oldGoogleId] = 'COMPLETED';
        $this->travelTo(CarbonImmutable::parse('2026-02-14 20:05:00', 'UTC'));

        $results = $this->sync($video);
        $this->assertCount(3, $results);
        $this->assertSame(ReportImportStatus::Completed, $results[0]->status, $results[0]->error_message ?? '');
        $this->assertSame(ReportFinality::Estimated, $results[0]->finality);
        $this->assertSame(ReportImportStatus::Pending, $results[1]->status);
        $this->assertSame(ReportFinality::Finalized, $results[1]->finality);
        $this->assertSame(ReportFinality::Estimated, $results[2]->finality);
        $this->assertCount(4, $this->google->requests); // primary, old estimate, fresh final, today
        $this->assertSame($this->google->requests[$oldGoogleId], $this->google->requests[3]);
        $row = $this->row($video);
        $this->assertSame(ReportFinality::Estimated, $row->finality);
        $this->assertFalse($row->settlement_eligible);
        $metricsBefore = $row->only(['ad_requests', 'matched_requests', 'impressions', 'clicks', 'gross_revenue_minor']);
        $rowId = $row->id;

        $this->google->status = 'COMPLETED';
        $this->travel(5)->minutes();
        $completed = $this->sync($video);
        $this->assertCount(2, $completed);
        foreach ($completed as $job) {
            $this->assertSame(ReportImportStatus::Completed, $job->status, $job->error_message ?? '');
        }
        $row = $this->row($video);
        $this->assertSame($rowId, $row->id);
        $this->assertSame($metricsBefore, $row->only(array_keys($metricsBefore)));
        $this->assertSame(ReportFinality::Finalized, $row->finality);
        $this->assertTrue($row->settlement_eligible);
        $this->assertSame(2, (int) $row->revision);
        $this->assertSame(2, DailyReport::withoutGlobalScopes()->where('report_source_connection_id', $video->report_source_connection_id)->count());
        $this->assertCount(4, $this->google->requests);
        $this->assertSame($scopeBefore, $video->connection->fresh()->configuration['site_report_scope']);
        $this->assertSame($primaryBefore, $primary->fresh()->getAttributes());
        $this->assertSame($connectionBefore, $primary->connection->fresh()->getAttributes());
        $this->assertSame($primaryRowBefore, $this->row($primary, '2026-02-13')->getAttributes());
        $this->assertSame([], $this->sync($video));
        $this->assertDatabaseCount('hourly_reports', 0);
        $this->assertSame(0, ReportImportJob::withoutGlobalScopes()->whereIn('status', ['PENDING', 'FAILED'])->count());
    }

    public function test_still_pending_prior_day_estimate_is_not_relabelled_finalized_or_requested_twice(): void
    {
        [, $video] = $this->makeReportBindings();
        $pending = $this->sync($video)[0];
        $this->travelTo(CarbonImmutable::parse('2026-02-14 20:05:00', 'UTC'));
        $results = $this->sync($video);
        $this->assertCount(2, $results); // old estimate plus new day, no final reuse
        $this->assertCount(2, $this->google->requests);
        $this->assertSame(ReportFinality::Estimated, $pending->fresh()->finality);
        $this->assertSame(ReportImportStatus::Pending, $pending->fresh()->status);
        $this->assertDatabaseCount('daily_reports', 0);
        $due = $video->connection->fresh()->configuration['sync_due']['daily_2026-02-14_2026-02-14'];
        $this->assertTrue(CarbonImmutable::parse($due)->equalTo(now()->addMinute()));
    }

    public function test_same_day_estimate_keeps_its_hourly_refresh_and_estimated_finality(): void
    {
        [, $video] = $this->makeReportBindings();
        $this->sync($video);
        $this->google->status = 'COMPLETED';
        $this->travel(5)->minutes();
        $results = $this->sync($video);
        $this->assertCount(1, $results);
        $this->assertSame(ReportImportStatus::Completed, $results[0]->status, $results[0]->error_message ?? '');
        $this->assertSame(ReportFinality::Estimated, $this->row($video)->finality);
        $this->assertFalse($this->row($video)->settlement_eligible);
        $due = $video->connection->fresh()->configuration['sync_due'];
        $this->assertArrayNotHasKey('daily_2026-02-14_2026-02-14', $due);
        $this->assertTrue(CarbonImmutable::parse($due['intraday_2026-02-14'])->equalTo(now()->addHour()));
        $this->assertSame([], $this->sync($video));
        $this->assertCount(1, $this->google->requests);
    }

    public function test_failed_prior_day_estimate_preserves_retry_backoff_instead_of_reusing_it_as_final(): void
    {
        [, $video] = $this->makeReportBindings();
        $this->sync($video);
        $this->travelTo(CarbonImmutable::parse('2026-02-14 20:05:00', 'UTC'));
        $this->google->failStatus = true;
        $results = $this->sync($video);
        $this->assertCount(2, $results);
        $this->assertSame(ReportImportStatus::Failed, $results[0]->status);
        $this->assertSame(ReportFinality::Estimated, $results[0]->finality);
        $due = $video->connection->fresh()->configuration['sync_due']['daily_2026-02-14_2026-02-14'];
        $this->assertTrue(CarbonImmutable::parse($due)->equalTo(now()->addMinutes((int) config('reporting.retry_delay_minutes', 30))));
        $this->assertCount(2, $this->google->requests);
        $this->assertDatabaseCount('daily_reports', 0);
    }

    public function test_existing_completed_estimate_cooldown_is_not_rewritten_by_a_recovery_migration(): void
    {
        [, $video] = $this->makeReportBindings();
        $this->google->status = 'COMPLETED';
        $this->sync($video);
        $this->travelTo(CarbonImmutable::parse('2026-02-14 20:05:00', 'UTC'));
        $configuration = $video->connection->fresh()->configuration;
        $configuration['sync_due']['daily_2026-02-14_2026-02-14'] = now()->addHours(6)->toIso8601String();
        $configuration['sync_due']['intraday_2026-02-15'] = now()->addHour()->toIso8601String();
        $video->connection->update(['configuration' => $configuration]);
        $configuration = $video->connection->fresh()->configuration;
        $rowBefore = $this->row($video)->getAttributes();
        $this->assertSame([], $this->sync($video));
        $this->assertSame($configuration, $video->connection->fresh()->configuration);
        $this->assertSame($rowBefore, $this->row($video)->getAttributes());
        $this->assertCount(1, $this->google->requests);
    }
}
