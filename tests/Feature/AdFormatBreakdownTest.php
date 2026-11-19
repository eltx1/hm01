<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\ReportFinality;
use App\Enums\ReportGranularity;
use App\Enums\ReportImportStatus;
use App\Enums\RoleName;
use App\Models\DailyReport;
use App\Models\GamConnection;
use App\Models\ReportDimension;
use App\Models\SiteGamAdFormatReport;
use App\Models\SiteGamReportBinding;
use App\Services\Gam\Contracts\GamSoapTransportInterface;
use App\Services\Gam\GamSoapPayloadHydrator;
use App\Services\Gam\GamSoapVersionResolver;
use App\Services\Reporting\AdFormatBreakdownService;
use App\Services\Reporting\ReportImportService;
use App\Services\Reporting\RevenueRuleService;
use App\Services\Reporting\SiteGamAdFormatSynchronizer;
use App\Services\Reporting\SiteGamReportMetrics;
use App\Services\Reporting\SiteGamReportingService;
use Carbon\CarbonImmutable;
use Database\Seeders\InventoryDeliverySeeder;
use Database\Seeders\ReportingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class AdFormatBreakdownTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private const DAY = '2026-09-20';

    private object $google;

    /** Rows Google returns for the ad format report: [date, site, format name, requests, matched, impressions, clicks, revenue micros]. */
    private array $formatRows = [];

    private function context(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21 10:00:00', 'UTC'));
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, ReportingSeeder::class]);
        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $org = $this->makeOrganization(OrganizationType::Publisher, 'Publisher');
        $user = $this->makeUser($org, RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($user);
        $site = $this->makeSiteFor($publisher, $user);
        $gam = $this->makeGamConnection($horus, $admin);
        $this->google = new class implements GamSoapTransportInterface
        {
            public array $calls = [];
            public bool $rejectFormat = false;
            public int $jobs = 0;

            public function call(GamConnection $connection, string $service, string $method, array $payload = []): array
            {
                $this->calls[] = compact('service', 'method', 'payload');
                // Exercise the generated SDK objects, including the format dimensions.
                $versions = app(GamSoapVersionResolver::class);
                $namespace = $versions->namespaceFor($versions->resolve());
                $reflection = new \ReflectionClass($namespace.'\\'.$service);
                app(GamSoapPayloadHydrator::class)->arguments($reflection->newInstanceWithoutConstructor(), $method, $payload, $namespace);
                if ($method === 'runReportJob' && $this->rejectFormat
                    && in_array('INVENTORY_FORMAT_NAME', $payload['reportJob']['reportQuery']['dimensions'], true)) {
                    throw new \RuntimeException('ReportError.COLUMNS_NOT_SUPPORTED_FOR_REQUESTED_DIMENSIONS');
                }

                return match ($method) {
                    'getCurrentNetwork' => ['networkCode' => $connection->network_code, 'currencyCode' => 'USD', 'timeZone' => 'Africa/Cairo'],
                    'getAdUnitsByStatement' => ['results' => [['id' => '12345', 'name' => 'Publisher unit', 'adUnitCode' => 'publisher_unit']]],
                    'runReportJob' => ['id' => (string) ++$this->jobs, 'reportQuery' => ['reportCurrency' => 'USD']],
                    'getReportJobStatus' => ['value' => 'COMPLETED'],
                    'getReportDownloadUrlWithOptions' => ['value' => 'https://storage.googleapis.com/report.csv?signature=private'],
                    default => throw new \RuntimeException('Unexpected Google call: '.$method),
                };
            }

            public function lastQuery(): array
            {
                return collect($this->calls)->where('method', 'runReportJob')->last()['payload']['reportJob']['reportQuery'];
            }
        };
        $this->app->instance(GamSoapTransportInterface::class, $this->google);
        Http::preventStrayRequests();
        Http::fake(['storage.googleapis.com/*' => fn () => Http::response($this->download())]);
        $binding = app(SiteGamReportingService::class)->bind($site, $gam->id, 'Publisher unit', $admin);
        app(RevenueRuleService::class)->createRule(['name' => 'Website split', 'scope_type' => 'WEBSITE', 'scope_id' => $site->id,
            'effective_from' => '2026-09-01', 'publisher_share_bp' => 8000, 'horus_share_bp' => 2000, 'mcm_partner_share_bp' => 0], $admin);

        return [$admin, $publisher, $user, $site, $binding];
    }

    /** Serve whichever report the fake Google job was asked for, within its own dates. */
    private function download(): string
    {
        $query = $this->google->lastQuery();
        $date = fn (array $day) => sprintf('%04d-%02d-%02d', $day['year'], $day['month'], $day['day']);
        [$from, $to] = [$date($query['startDate']), $date($query['endDate'])];
        $stream = fopen('php://temp', 'w+');
        if (in_array('INVENTORY_FORMAT_NAME', $query['dimensions'], true)) {
            fputcsv($stream, ['Dimension.DATE', 'Dimension.AD_UNIT_ID', 'Dimension.SITE_NAME', 'Dimension.INVENTORY_FORMAT', 'Dimension.INVENTORY_FORMAT_NAME',
                ...array_map(fn ($column) => 'Column.'.$column, $query['columns'])], escape: '');
            foreach ($this->formatRows as $index => [$day, $site, $name, $requests, $matched, $impressions, $clicks, $micros]) {
                if ($day < $from || $day > $to) continue;
                $values = [$requests, $matched, $impressions, $clicks, 'US$ '.$micros];
                if (count($query['columns']) > count(SiteGamReportMetrics::CORE_COLUMNS)) array_push($values, intdiv($impressions, 2), $impressions);
                fputcsv($stream, [$day, '12345', $site, (string) ($index + 1), $name, ...$values], escape: '');
            }
        } else {
            // The unchanged ledger import: one exact-site total for the day.
            fputcsv($stream, ['Dimension.DATE', 'Dimension.AD_UNIT_ID', 'Dimension.SITE_NAME', ...array_map(fn ($column) => 'Column.'.$column, $query['columns'])], escape: '');
            if (self::DAY >= $from && self::DAY <= $to) {
                fputcsv($stream, [self::DAY, '12345', $this->hostname(), 120, 100, 100, 4, 'US$ 100000000', 50, 100], escape: '');
            }
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    private function hostname(): string
    {
        return \App\Models\Site::withoutGlobalScopes()->firstOrFail()->primary_domain;
    }

    private function importLedger(SiteGamReportBinding $binding): void
    {
        $job = app(ReportImportService::class)->runConnection($binding->connection, CarbonImmutable::parse(self::DAY),
            CarbonImmutable::parse(self::DAY), ReportGranularity::Daily, ReportFinality::Finalized);
        $this->assertSame(ReportImportStatus::Completed, $job->status, $job->error_message ?? '');
    }

    private function syncFormats(SiteGamReportBinding $binding): array
    {
        $sync = app(SiteGamAdFormatSynchronizer::class);
        $result = [];
        for ($pass = 0; $pass < 30; $pass++) {
            $result = $sync->sync($binding);
            if (! in_array($result['status'], ['COMPLETED', 'PENDING'], true)) break;
        }

        return $result;
    }

    private function threeFormats(): void
    {
        $host = $this->hostname();
        $this->formatRows = [
            [self::DAY, $host, 'Banner', 70, 60, 60, 2, 60000000],
            [self::DAY, $host, 'Interstitial', 30, 25, 25, 1, 30000000],
            [self::DAY, $host, 'Rewarded', 20, 15, 15, 1, 10000000],
            // Another hostname on the same unit is never attributed to this website.
            [self::DAY, 'other.example', 'Anchor', 99, 99, 99, 9, 99000000],
        ];
    }

    public function test_sync_uses_the_bound_unit_exact_site_and_inventory_format_without_changing_financial_rows(): void
    {
        [, , , $site, $binding] = $this->context();
        $this->importLedger($binding);
        $before = DailyReport::withoutGlobalScopes()->orderBy('id')->get()->map->getAttributes()->all();
        $dimensions = ReportDimension::withoutGlobalScopes()->orderBy('id')->get()->map->getAttributes()->all();
        $this->threeFormats();

        $this->assertSame('CURRENT', $this->syncFormats($binding)['status']);

        $query = $this->google->lastQuery();
        $this->assertSame(['DATE', 'AD_UNIT_ID', 'SITE_NAME', 'INVENTORY_FORMAT', 'INVENTORY_FORMAT_NAME'], $query['dimensions']);
        $this->assertSame(array_keys(SiteGamReportMetrics::COLUMNS), $query['columns']);
        $this->assertSame('FLAT', $query['adUnitView']);
        $this->assertSame('USD', $query['reportCurrency']);
        $this->assertSame('PUBLISHER', $query['timeZoneType']);
        $this->assertSame('12345', $query['statement']['values'][0]['value']['value']);
        $rows = SiteGamAdFormatReport::withoutGlobalScopes()->orderBy('ad_format')->get();
        $this->assertSame(['BANNER', 'INTERSTITIAL', 'REWARDED'], $rows->pluck('ad_format')->all());
        $this->assertSame([60000000, 30000000, 10000000], $rows->pluck('revenue_micros')->all());
        $this->assertSame([$site->id], $rows->pluck('site_id')->unique()->values()->all());
        $this->assertSame([$binding->id], $rows->pluck('site_gam_report_binding_id')->unique()->values()->all());
        $this->assertSame(30, $rows->firstWhere('ad_format', 'BANNER')->active_view_viewable_impressions);
        // Ledger facts and dimensions are untouched.
        $this->assertSame($before, DailyReport::withoutGlobalScopes()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($dimensions, ReportDimension::withoutGlobalScopes()->orderBy('id')->get()->map->getAttributes()->all());

        // A corrected snapshot replaces the day's formats; it never adds up.
        $this->formatRows = [[self::DAY, $this->hostname(), 'Banner', 100, 90, 90, 3, 100000000]];
        $binding->connection->update(['configuration' => array_replace_recursive($binding->connection->fresh()->configuration,
            [SiteGamAdFormatSynchronizer::STATE_KEY => ['next_refresh_at' => now()->subMinute()->toIso8601String()]])]);
        $this->syncFormats($binding);
        $this->assertSame(['BANNER'], SiteGamAdFormatReport::withoutGlobalScopes()->pluck('ad_format')->all());
        $this->assertSame($before, DailyReport::withoutGlobalScopes()->orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_ledger_amounts_are_split_by_format_and_always_add_up_to_the_main_report(): void
    {
        [, , , $site, $binding] = $this->context();
        $this->importLedger($binding);
        $this->threeFormats();
        $this->syncFormats($binding);
        $ledger = DailyReport::withoutGlobalScopes()->sole();
        $this->assertSame(10000, (int) $ledger->gross_revenue_minor);
        $this->assertSame(8000, (int) $ledger->publisher_earnings_minor);

        $sites = collect([$site->fresh()]);
        $publisher = app(AdFormatBreakdownService::class)->summary($sites, self::DAY, self::DAY, publisherView: true);
        $formats = $publisher['formats']->keyBy('key');
        $this->assertSame(['BANNER', 'INTERSTITIAL', 'REWARDED'], $publisher['formats']->pluck('key')->all());
        $this->assertSame([4800, 2400, 800], [$formats['BANNER']['earnings_minor'], $formats['INTERSTITIAL']['earnings_minor'], $formats['REWARDED']['earnings_minor']]);
        $this->assertSame(8000, $publisher['totals']['earnings_minor']);
        $this->assertSame(6000, $formats['BANNER']['share_bp']);
        $this->assertSame(60, $formats['BANNER']['impressions']);
        $this->assertSame(80000, $formats['BANNER']['ecpm_minor']);
        $this->assertSame(5000, $formats['BANNER']['viewability_bp']);
        $this->assertSame(10, $formats['BANNER']['ad_exchange_unmatched_requests']);
        $this->assertNull($publisher['not_broken_down']);
        foreach ([...$publisher['formats'], $publisher['totals']] as $row) {
            $this->assertArrayNotHasKey('gross_revenue_minor', $row);
            $this->assertArrayNotHasKey('horus_earnings_minor', $row);
        }

        $admin = app(AdFormatBreakdownService::class)->summary($sites, self::DAY, self::DAY, publisherView: false);
        $this->assertSame(10000, $admin['totals']['gross_revenue_minor']);
        $this->assertSame(8000, $admin['totals']['publisher_earnings_minor']);
        $this->assertSame(2000, $admin['totals']['horus_earnings_minor']);
        $this->assertSame(6000, $admin['formats']->firstWhere('key', 'BANNER')['gross_revenue_minor']);
        $this->assertSame([self::DAY], $admin['days']->pluck('date')->all());
        $this->assertSame(10000, $admin['days'][0]['total']);
    }

    public function test_days_without_format_evidence_stay_visible_as_not_broken_down(): void
    {
        [, , , $site, $binding] = $this->context();
        $this->importLedger($binding);

        $summary = app(AdFormatBreakdownService::class)->summary(collect([$site->fresh()]), self::DAY, self::DAY, publisherView: true);
        $this->assertFalse($summary['has_breakdown']);
        $this->assertSame(['NOT_BROKEN_DOWN'], $summary['formats']->pluck('key')->all());
        $this->assertSame(8000, $summary['not_broken_down']['earnings_minor']);
        $this->assertSame(100, $summary['not_broken_down']['impressions']);
        $this->assertSame(8000, $summary['totals']['earnings_minor']);
    }

    public function test_unsupported_format_dimension_backs_off_without_touching_the_ledger(): void
    {
        [, , , , $binding] = $this->context();
        $this->importLedger($binding);
        $before = DailyReport::withoutGlobalScopes()->orderBy('id')->get()->map->getAttributes()->all();
        $this->google->rejectFormat = true;

        $this->assertSame('FORMAT_DIMENSION_UNSUPPORTED', app(SiteGamAdFormatSynchronizer::class)->sync($binding)['status']);
        $state = $binding->connection->fresh()->configuration[SiteGamAdFormatSynchronizer::STATE_KEY];
        $this->assertTrue(CarbonImmutable::parse($state['retry_at'])->gt(now()->addHours(23)));
        $this->assertArrayNotHasKey('pending', $state);
        $calls = count($this->google->calls);
        $this->assertSame('FORMAT_DIMENSION_UNSUPPORTED', app(SiteGamAdFormatSynchronizer::class)->sync($binding)['status']);
        $this->assertCount($calls, $this->google->calls, 'An unsupported network must not be asked again before the back-off.');
        $this->assertSame(0, SiteGamAdFormatReport::withoutGlobalScopes()->count());
        $this->assertSame($before, DailyReport::withoutGlobalScopes()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame('ACTIVE', $binding->connection->fresh()->status->value);
    }

    public function test_publisher_page_button_csv_and_privacy(): void
    {
        [, , $user, $site, $binding] = $this->context();
        $this->importLedger($binding);
        $this->threeFormats();
        $this->syncFormats($binding);

        $this->actingAs($user)->get(route('publisher.reporting.index', ['from' => self::DAY, 'to' => self::DAY]))
            ->assertOk()->assertSee('Breakdown by Ad Format')
            ->assertSee(route('publisher.reporting.ad-formats', ['from' => self::DAY, 'to' => self::DAY]));

        $this->get(route('publisher.reporting.ad-formats', ['from' => self::DAY, 'to' => self::DAY]))
            ->assertOk()
            ->assertSeeInOrder(['Banner', 'Interstitial', 'Rewarded'])
            ->assertSee('48.00')->assertSee('24.00')->assertSee('8.00')
            ->assertDontSee('Horus margin')->assertDontSee('Gross revenue')->assertDontSee('data-ad-format="ANCHOR"', false);
        $this->get(route('publisher.reporting.ad-formats', ['from' => self::DAY, 'to' => self::DAY, 'site' => $site->id]))->assertOk()->assertSee('Banner');

        $csv = $this->get(route('publisher.reporting.ad-formats', ['from' => self::DAY, 'to' => self::DAY, 'export' => 'csv']))
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('Ad format', $csv);
        $this->assertStringContainsString('Banner', $csv);
        $this->assertStringContainsString('48.00', $csv);
        $this->assertStringNotContainsString('Horus margin', $csv);

        // Another publisher's website is never reachable through the site filter.
        $otherOrg = $this->makeOrganization(OrganizationType::Publisher, 'Other publisher');
        $otherUser = $this->makeUser($otherOrg, RoleName::PublisherAdmin);
        $this->makePublisherFor($otherUser);
        $this->actingAs($otherUser)->get(route('publisher.reporting.ad-formats', ['from' => self::DAY, 'to' => self::DAY, 'site' => $site->id]))->assertNotFound();
        $this->actingAs($otherUser)->get(route('publisher.reporting.ad-formats', ['from' => self::DAY, 'to' => self::DAY]))
            ->assertOk()->assertDontSee('data-ad-format="INTERSTITIAL"', false)->assertSee('No reports for these dates');
        // Admin routes stay staff-only: another tenant cannot even resolve the publisher,
        // and the publisher itself is refused by the staff permission gate.
        $this->actingAs($otherUser)->get(route('admin.reporting.publishers.ad-formats', ['publisher' => $site->publisher_id]))->assertNotFound();
        $this->actingAs($user)->get(route('admin.reporting.publishers.ad-formats', ['publisher' => $site->publisher_id]))->assertForbidden();
    }

    public function test_admin_sees_the_publisher_breakdown_with_gross_and_margin(): void
    {
        [$admin, $publisher, , $site, $binding] = $this->context();
        $this->importLedger($binding);
        $this->threeFormats();
        $this->syncFormats($binding);
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);

        $this->get(route('admin.reporting.websites.show', ['site' => $site, 'from' => self::DAY, 'to' => self::DAY]))
            ->assertOk()->assertSee('Breakdown by Ad Format');
        $this->get(route('admin.reporting.publishers.ad-formats', ['publisher' => $publisher, 'site' => $site->id, 'from' => self::DAY, 'to' => self::DAY]))
            ->assertOk()
            ->assertSeeInOrder(['Banner', 'Interstitial', 'Rewarded'])
            ->assertSee('Horus margin')->assertSee('60.00')->assertSee('Publisher earnings');
        $this->get(route('admin.reporting.publishers.ad-formats', ['publisher' => $publisher, 'from' => self::DAY, 'to' => self::DAY]))
            ->assertOk()->assertSee('All websites');
        $this->get(route('admin.reporting.publishers.ad-formats', ['publisher' => $publisher, 'site' => '01JFOREIGNSITE0000000000000']))->assertNotFound();
        $csv = $this->get(route('admin.reporting.publishers.ad-formats', ['publisher' => $publisher, 'from' => self::DAY, 'to' => self::DAY, 'export' => 'csv']))
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('Horus margin', $csv);
        $this->assertStringContainsString('60.00', $csv);
    }

    public function test_all_websites_view_matches_the_publisher_main_report_including_amounts_without_a_website(): void
    {
        [, $publisher, $user, $site, $binding] = $this->context();
        $this->importLedger($binding);
        $this->threeFormats();
        $this->syncFormats($binding);
        // A publisher-level fact that is not tied to one website (e.g. an unmapped demand row).
        $original = DailyReport::withoutGlobalScopes()->sole();
        $dimension = ReportDimension::withoutGlobalScopes()->create(['organization_id' => $publisher->organization_id,
            'publisher_id' => $publisher->id, 'dimension_hash' => hash('sha256', 'publisher-level-test')]);
        $extra = $original->replicate();
        $extra->forceFill(['report_dimension_id' => $dimension->id, 'publisher_earnings_minor' => 500,
            'gross_revenue_minor' => 625, 'horus_earnings_minor' => 125, 'impressions' => 7])->save();

        $main = app(\App\Services\Reporting\PublisherPerformanceService::class)->summary($publisher, self::DAY, self::DAY);
        $this->assertSame(8500, $main['earnings_minor']);
        $all = app(AdFormatBreakdownService::class)->summary(collect([$site->fresh()]), self::DAY, self::DAY, publisherView: true, publisher: $publisher);
        $this->assertSame($main['earnings_minor'], $all['totals']['earnings_minor']);
        $this->assertSame(500, $all['not_broken_down']['earnings_minor']);
        $this->assertSame(4800, $all['formats']->firstWhere('key', 'BANNER')['earnings_minor']);
        // A single website never includes publisher-level amounts.
        $one = app(AdFormatBreakdownService::class)->summary(collect([$site->fresh()]), self::DAY, self::DAY, publisherView: true);
        $this->assertSame(8000, $one['totals']['earnings_minor']);
        $this->assertNull($one['not_broken_down']);

        $this->actingAs($user)->get(route('publisher.reporting.ad-formats', ['from' => self::DAY, 'to' => self::DAY]))
            ->assertOk()->assertSee('Not broken down')->assertSee('85.00');
        $this->get(route('publisher.reporting.ad-formats', ['from' => self::DAY, 'to' => self::DAY, 'site' => $site->id]))
            ->assertOk()->assertDontSee('data-ad-format="NOT_BROKEN_DOWN"', false);
    }

    public function test_admin_sees_format_sync_status_and_entry_points_without_calling_google(): void
    {
        [$admin, $publisher, , $site, $binding] = $this->context();
        $this->importLedger($binding);
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        $sync = app(SiteGamAdFormatSynchronizer::class);
        $this->assertSame('WAITING', $sync->status($site->fresh())['state']);

        $this->threeFormats();
        $this->syncFormats($binding);
        $status = $sync->status($site->fresh());
        $this->assertSame('CURRENT', $status['state']);
        $this->assertSame('2026-09-21', $status['synced_through'], 'The hourly refresh includes today’s running totals.');
        $calls = count($this->google->calls);
        $this->get(route('admin.reporting.publishers.ad-formats', ['publisher' => $publisher, 'from' => self::DAY, 'to' => self::DAY]))
            ->assertOk()->assertSee('Ad format data status')->assertSee('data-ad-format-sync="CURRENT"', false)->assertSee('21 Sep 2026');
        $this->assertCount($calls, $this->google->calls, 'Viewing reports must never call Google.');

        $binding->connection->update(['configuration' => array_replace_recursive($binding->connection->fresh()->configuration, [
            SiteGamAdFormatSynchronizer::STATE_KEY => ['error' => 'FORMAT_DIMENSION_UNSUPPORTED', 'retry_at' => now()->addDay()->toIso8601String()],
        ])]);
        $this->assertSame('UNSUPPORTED', $sync->status($site->fresh())['state']);
        $this->get(route('admin.reporting.publishers.ad-formats', ['publisher' => $publisher, 'site' => $site->id]))
            ->assertOk()->assertSee('data-ad-format-sync="UNSUPPORTED"', false);

        $other = $this->makeSiteFor($publisher, $admin, ['primary_domain' => 'second-site.example', 'display_name' => 'Second site']);
        $this->assertSame('NOT_CONNECTED', $sync->status($other)['state']);

        $this->get(route('admin.publishers.show', $publisher))->assertOk()
            ->assertSee(route('admin.reporting.publishers.ad-formats', $publisher));
        $this->get(route('admin.reporting.websites.index', ['from' => self::DAY, 'to' => self::DAY]))->assertOk()->assertSee('Breakdown by Ad Format');
    }
}
