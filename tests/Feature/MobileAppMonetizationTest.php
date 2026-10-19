<?php

namespace Tests\Feature;

use App\Enums\{OrganizationType, RoleName, ReportFinality, ReportGranularity};
use App\Models\{DailyReport, GamConnection, MobileApp, MobileAppAdUnit, PlatformAdsTxtRecord, ReportSource, ReportSourceConnection, SellerDeclaration};
use App\Services\Gam\Contracts\GamSoapTransportInterface;
use App\Services\MobileApps\{AppStoreUrl, AppAdsTxtService, MobileAppService, MobileAppReporting, MobileAppReportScope, MobileAppReportSynchronizer, MobileAppFinancialCoverage};
use App\Services\Network\Contracts\DnsResolver;
use App\Services\Reporting\{ReportImportService, RevenueRuleService, FinancialPeriodService, SiteGamReportingService, SiteGamReportMetrics, ReportDimensionResolver, PublisherPerformanceService};
use App\Services\SupplyChain\SupplyChainStandardsContract;
use Carbon\CarbonImmutable;
use Database\Seeders\{InventoryDeliverySeeder, ReportingSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\{InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites};
use Tests\TestCase;

class MobileAppMonetizationTest extends TestCase
{
    use RefreshDatabase, InteractsWithIdentity, InteractsWithGam, InteractsWithPublisherSites;
    private object $google;

    private function context(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-11 10:00:00', 'UTC'));
        $this->withoutVite();
        $this->withSession(['two_factor_passed_at' => now()->timestamp]);
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, ReportingSeeder::class]);
        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus');
        $admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($user, ['business_domain' => 'developer.example.com']);
        $app = app(MobileAppService::class)->create($publisher, $this->input(), $user);
        $gam = $this->makeGamConnection($horus, $admin);
        $this->google = new class implements GamSoapTransportInterface {
            public int $jobs = 0;
            public string $status = 'COMPLETED';
            public string $unit = '12345';
            public array $queries = [];
            public function call(GamConnection $connection, string $service, string $method, array $payload = []): array
            {
                if ($method === 'runReportJob') $this->queries[] = $payload['reportJob']['reportQuery'];
                return match ($method) {
                    'getCurrentNetwork' => ['networkCode' => $connection->network_code, 'currencyCode' => 'USD', 'timeZone' => 'UTC'],
                    'getAdUnitsByStatement' => ['results' => [['id' => $this->unit, 'name' => 'App banner', 'adUnitCode' => 'app_banner']]],
                    'runReportJob' => ['id' => (string) ++$this->jobs, 'reportQuery' => ['reportCurrency' => 'USD']],
                    'getReportJobStatus' => ['value' => $this->status],
                    'getReportDownloadUrlWithOptions' => ['value' => 'https://storage.googleapis.com/app-report.csv'],
                    default => throw new \RuntimeException($method),
                };
            }
        };
        $this->app->instance(GamSoapTransportInterface::class, $this->google);
        $this->app->instance(DnsResolver::class, new class implements DnsResolver {
            public function addresses(string $hostname): array { return ['8.8.8.8']; }
        });
        Http::preventStrayRequests();
        return [$admin, $publisher, $user, $app, $gam];
    }

    private function input(): array
    {
        return ['name' => 'Horus Reader', 'store_url' => 'https://play.google.com/store/apps/details?id=com.horus.reader', 'developer_domain' => 'developer.example.com'];
    }

    private function csv(array $rows = [['2026-10-10', '12345', 'com.horus.reader', 120, 100, 95, 3, 'US$ 100000000', 40, 80]]): string
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, ['Dimension.DATE', 'Dimension.AD_UNIT_ID', 'Dimension.MOBILE_APP_RESOLVED_ID',
            ...array_map(fn ($key) => 'Column.'.$key, array_keys(SiteGamReportMetrics::COLUMNS))], escape: '');
        foreach ($rows as $row) fputcsv($stream, $row, escape: '');
        rewind($stream); $body = stream_get_contents($stream); fclose($stream); return $body;
    }

    private function import($binding, string $day = '2026-10-10')
    {
        return app(ReportImportService::class)->runConnection($binding->connection, CarbonImmutable::parse($day), CarbonImmutable::parse($day), ReportGranularity::Daily, ReportFinality::Finalized);
    }

    public static function stores(): array
    {
        return [
            ['https://play.google.com/store/apps/details?id=com.horus.reader&hl=en', null, 'google_play', 'com.horus.reader'],
            ['https://apps.apple.com/us/app/reader/id123456789', null, 'apple', '123456789'],
            ['https://www.amazon.com/reader/dp/B012345678', 'com.horus.reader', 'amazon', 'com.horus.reader'],
            ['https://galaxystore.samsung.com/detail/com.horus.reader', null, 'samsung', 'com.horus.reader'],
            ['https://store.oppomobile.com/app/detail?id=123', 'com.horus.reader', 'oppo', 'com.horus.reader'],
            ['https://h5.appstore.vivo.com.cn/#/appDetail/123', 'com.horus.reader', 'vivo', 'com.horus.reader'],
            ['https://app.market.xiaomi.com/details?id=com.horus.reader', 'com.horus.reader', 'xiaomi', 'com.horus.reader'],
        ];
    }

    #[DataProvider('stores')]
    public function test_supported_store_identities(string $url, ?string $package, string $store, string $id): void
    {
        $result = app(AppStoreUrl::class)->parse($url, $package);
        $this->assertSame($store, $result['store']); $this->assertSame($id, $result['store_app_id']);
    }

    public static function unsafeUrls(): array
    {
        return [['http://play.google.com/store/apps/details?id=com.horus.reader'], ['https://play.google.com.evil.com/store/apps/details?id=com.horus.reader'], ['https://x@play.google.com/store/apps/details?id=com.horus.reader'], ['https://play.google.com:8443/store/apps/details?id=com.horus.reader'], ['https://play.google.com/store/apps/details?id[]=com.horus.reader']];
    }

    #[DataProvider('unsafeUrls')]
    public function test_unsafe_urls_are_rejected(string $url): void
    {
        $this->expectException(ValidationException::class); app(AppStoreUrl::class)->parse($url);
    }

    public function test_onboarding_creates_app_and_public_seller_identity_without_a_fake_website(): void
    {
        [$admin, $publisher, $user, $app] = $this->context();
        $this->assertSame(0, $publisher->sites()->count());
        $this->assertStringStartsWith('hma_', $app->public_id);
        $this->assertStringStartsWith('HMA-', $app->seller->seller_id);
        $sellers = collect(app(SupplyChainStandardsContract::class)->sellers()['sellers'])->pluck('payload.seller_id');
        $this->assertContains($app->seller->seller_id, $sellers);
        $this->assertSame('PUBLISHER', $app->seller->seller_type);
        $this->actingAs($user)->get(route('publisher.mobile-apps.index'))->assertOk()->assertSee('Horus Reader');
        $this->get(route('publisher.mobile-apps.show', $app))->assertOk()->assertSee('app-ads.txt');
        $this->get(route('publisher.mobile-apps.create'))->assertOk();
        $this->actingAs($admin)->get(route('admin.mobile-apps.show', $app))->assertOk()->assertSee('Reporting sources');
    }

    public function test_tenant_isolation_and_publisher_cannot_activate_or_assign_another_publisher(): void
    {
        [$admin, $publisher, $user, $app] = $this->context();
        $other = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $this->makePublisherFor($other);
        $this->actingAs($other)->get(route('publisher.mobile-apps.show', $app))->assertNotFound();
        $this->get(route('publisher.mobile-apps.reports', $app))->assertNotFound();
        $this->actingAs($user)->post(route('admin.mobile-apps.settings', $app), ['status' => 'ACTIVE'])->assertForbidden();
        $data = $this->input(); $data['store_url'] .= '2'; $data['publisher_id'] = $admin->id; $data['status'] = 'ACTIVE';
        $this->post(route('publisher.mobile-apps.store'), $data)->assertRedirect();
        $new = MobileApp::withoutGlobalScopes()->where('store_app_id', 'com.horus.reader2')->firstOrFail();
        $this->assertSame($publisher->id, $new->publisher_id); $this->assertSame('PENDING', $new->status);
    }

    public function test_app_ads_uses_master_and_current_domain_evidence(): void
    {
        [$admin, $publisher, $user, $app] = $this->context();
        PlatformAdsTxtRecord::create(['advertising_system_domain' => 'google.com', 'publisher_account_id' => 'pub-1234567890123456', 'relationship' => 'RESELLER', 'certification_authority_id' => 'f08c47fec0942fa0', 'record_hash' => hash('sha256', 'master'), 'raw_record' => 'google.com, pub-1234567890123456, RESELLER, f08c47fec0942fa0', 'status' => 'ACTIVE', 'review_status' => 'VERIFIED']);
        $ads = app(AppAdsTxtService::class); $content = $ads->content($app);
        $this->assertStringContainsString('google.com, pub-1234567890123456, RESELLER, f08c47fec0942fa0', $content);
        $this->assertStringContainsString('HMP-', $content); $this->assertStringContainsString('HMA-', $content);
        Http::fake(['*' => Http::response($content, 200, ['Content-Type' => 'text/plain'])]);
        $this->assertTrue($ads->verify($app, $user)); $this->assertTrue($ads->current($app->fresh()));
        Http::assertSent(fn ($r) => $r->url() === 'https://developer.example.com/app-ads.txt');
        PlatformAdsTxtRecord::query()->update(['status' => 'DISABLED']);
        $this->assertFalse($ads->current($app->fresh()));
        app(MobileAppService::class)->changeDomain($app, 'new.example.com', $user);
        $this->assertNull($app->fresh()->app_ads_evidence);
    }

    public function test_domain_change_during_fetch_cannot_verify_stale_evidence(): void
    {
        [$admin, $publisher, $user, $app] = $this->context();
        $content = app(AppAdsTxtService::class)->content($app);
        Http::fake(function () use ($app, $user, $content) {
            app(MobileAppService::class)->changeDomain($app, 'new.example.com', $user);
            return Http::response($content, 200, ['Content-Type' => 'text/plain']);
        });
        $this->expectException(ValidationException::class);
        try { app(AppAdsTxtService::class)->verify($app, $user); }
        finally { $this->assertNull($app->fresh()->app_ads_evidence); }
    }

    public function test_report_is_exact_app_net_for_publisher_and_gross_for_admin(): void
    {
        [$admin, $publisher, $user, $app, $gam] = $this->context();
        app(RevenueRuleService::class)->createRule(['name' => 'Publisher 80%', 'scope_type' => 'PUBLISHER', 'scope_id' => $publisher->id,
            'effective_from' => '2026-10-01', 'publisher_share_bp' => 8000, 'horus_share_bp' => 2000, 'mcm_partner_share_bp' => 0], $admin);
        $binding = app(MobileAppReporting::class)->bind($app, $gam->id, '12345', $admin);
        $rows = [['2026-10-10', '12345', 'com.horus.reader', 120, 100, 95, 3, 'US$ 100000000', 40, 80], ['2026-10-10', '12345', 'com.other.app', 1000, 900, 800, 80, 'US$ 999000000', 400, 500]];
        Http::fake(fn () => Http::response($this->csv($rows)));
        $job = $this->import($binding); $this->assertSame('COMPLETED', $job->status->value, $job->error_message ?? '');
        $fact = DailyReport::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(10000, $fact->gross_revenue_minor); $this->assertSame(8000, $fact->publisher_earnings_minor);
        $this->assertSame($app->id, $fact->dimension->mobile_app_id); $this->assertNull($fact->dimension->site_id);
        $this->assertSame(95, $fact->impressions); $this->assertTrue($fact->settlement_eligible);
        $this->assertContains('MOBILE_APP_RESOLVED_ID', $this->google->queries[0]['dimensions']);
        $this->assertSame('FLAT', $this->google->queries[0]['adUnitView']);
        $this->import($binding); $this->assertSame(1, DailyReport::withoutGlobalScopes()->count());
        $this->actingAs($user)->get(route('publisher.mobile-apps.reports', [$app, 'from' => '2026-10-10', 'to' => '2026-10-10']))->assertOk()->assertSee('80.00')->assertDontSee('100.00');
        $this->get(route('publisher.mobile-apps.reports', [$app, 'from' => '2026-10-10', 'to' => '2026-10-10', 'export' => 1]))->assertOk()->assertDownload();
        $this->actingAs($admin)->get(route('admin.mobile-apps.reports', [$app, 'from' => '2026-10-10', 'to' => '2026-10-10']))->assertOk()->assertSee('100.00');
        $summary = app(PublisherPerformanceService::class)->summary($publisher, '2026-10-10', '2026-10-10');
        $this->assertSame(8000, $summary['earnings_minor']); $this->assertCount(1, $summary['apps']); $this->assertCount(0, $summary['websites']);
    }

    public function test_missing_app_dimension_fails_closed(): void
    {
        [$admin, $publisher, $user, $app, $gam] = $this->context();
        $binding = app(MobileAppReporting::class)->bind($app, $gam->id, '12345', $admin);
        Http::fake(['*' => Http::response(str_replace('Dimension.MOBILE_APP_RESOLVED_ID', 'Dimension.SITE_NAME', $this->csv()))]);
        $job = $this->import($binding); $this->assertSame('FAILED', $job->status->value); $this->assertSame(0, DailyReport::withoutGlobalScopes()->count());
    }

    public function test_units_have_one_financial_owner_across_apps_and_websites(): void
    {
        [$admin, $publisher, $user, $app, $gam] = $this->context();
        $first = app(MobileAppReporting::class)->bind($app, $gam->id, '12345', $admin);
        $this->assertSame($first->id, app(MobileAppReporting::class)->bind($app, $gam->id, '12345', $admin)->id);
        $this->google->unit = '98765';
        app(MobileAppReporting::class)->bind($app, $gam->id, '98765', $admin);
        $this->assertSame(2, $app->reportBindings()->count());
        $site = $this->makeSiteFor($publisher, $user);
        $this->google->unit = '12345';
        $this->expectException(ValidationException::class);
        app(SiteGamReportingService::class)->bind($site, $gam->id, '12345', $admin);
    }

    public function test_pending_reports_resume_and_zero_snapshots_correct_previous_earnings(): void
    {
        [$admin, $publisher, $user, $app, $gam] = $this->context();
        $binding = app(MobileAppReporting::class)->bind($app, $gam->id, '12345', $admin);
        $this->google->status = 'IN_PROGRESS';
        $this->assertSame('PENDING', $this->import($binding)->status->value);
        $this->google->status = 'COMPLETED';
        Http::fake(['*' => Http::sequence()->push($this->csv())->push($this->csv([]))]);
        $this->assertSame('COMPLETED', $this->import($binding)->status->value);
        $this->assertSame(1, $this->google->jobs);
        $this->assertSame('COMPLETED', $this->import($binding)->status->value);
        $this->assertSame(0, DailyReport::withoutGlobalScopes()->firstOrFail()->gross_revenue_minor);
    }

    public function test_seller_identity_and_store_ownership_cannot_be_reassigned(): void
    {
        [$admin, $publisher, $user, $app] = $this->context();
        $seller = $app->seller;
        $this->expectException(\LogicException::class); $seller->update(['mobile_app_id' => null]);
    }

    public function test_app_share_override_uses_existing_financial_engine(): void
    {
        [$admin, $publisher, $user, $app, $gam] = $this->context();
        $this->actingAs($admin)->post(route('admin.mobile-apps.share', $app), ['publisher_share' => '65', 'effective_from' => '2026-10-01', 'reason' => 'Agreed app commercial share'])->assertSessionHasNoErrors()->assertRedirect();
        $binding = app(MobileAppReporting::class)->bind($app, $gam->id, '12345', $admin);
        Http::fake(['*' => Http::response($this->csv())]);
        $this->assertSame('COMPLETED', $this->import($binding)->status->value);
        $this->assertSame(6500, DailyReport::withoutGlobalScopes()->firstOrFail()->publisher_earnings_minor);
        $this->get(route('admin.finance.revenue-rules.index'))->assertOk()->assertSee('One mobile app');
    }

    public function test_activation_requires_review_and_current_ads_and_supports_all_six_formats(): void
    {
        [$admin, $publisher, $user, $app, $gam] = $this->context();
        $settings = ['status' => 'ACTIVE', 'gam_connection_id' => $gam->id, 'sdk_app_id' => 'ca-app-pub-1234567890123456~1234567890', 'store_reviewed' => 1];
        $this->actingAs($admin)->post(route('admin.mobile-apps.settings', $app), $settings)->assertSessionHasErrors('status');
        $this->post(route('admin.mobile-apps.settings', $app), array_replace($settings, ['status' => 'APPROVED']))->assertSessionHasNoErrors();
        foreach (array_keys(config('mobile-apps.formats')) as $format) {
            $this->post(route('admin.mobile-apps.units', $app), ['name' => $format, 'format' => $format, 'ad_unit_path' => '/'.$gam->network_code.'/'.$format, 'is_enabled' => 1])->assertSessionHasNoErrors();
        }
        $this->assertSame(6, $app->adUnits()->count());
        $ads = app(AppAdsTxtService::class); Http::fake(['*' => Http::response($ads->content($app), 200, ['Content-Type' => 'text/plain'])]);
        $this->assertTrue($ads->verify($app, $admin));
        $this->post(route('admin.mobile-apps.settings', $app), $settings)->assertSessionHasNoErrors();
        $this->assertSame('ACTIVE', $app->fresh()->status);
        $this->actingAs($user)->get(route('publisher.mobile-apps.show', $app))->assertOk()->assertSee($settings['sdk_app_id'])->assertSee('Rewarded interstitial');
    }

    public function test_one_sdk_path_supports_all_formats_without_duplicating_reports(): void
    {
        [$admin, $publisher, $user, $app, $gam] = $this->context();
        $app->update(['gam_connection_id' => $gam->id, 'status' => 'ACTIVE', 'sdk_app_id' => 'ca-app-pub-1234567890123456~1234567890']);
        $path = '/'.$gam->network_code.'/app_banner';
        $this->actingAs($admin);
        foreach (array_keys(config('mobile-apps.formats')) as $format) {
            $this->post(route('admin.mobile-apps.units', $app), [
                'name' => $format, 'format' => $format, 'ad_unit_path' => $path, 'is_enabled' => 1,
            ])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertSame(6, $app->adUnits()->where('ad_unit_path', $path)->count());
        $page = $this->actingAs($user)->get(route('publisher.mobile-apps.show', $app))->assertOk();
        foreach (config('mobile-apps.formats') as $label) $page->assertSee($label);

        $this->actingAs($admin);
        $binding = app(MobileAppReporting::class)->bind($app, $gam->id, '12345', $admin);
        $this->assertSame($binding->id, app(MobileAppReporting::class)->bind($app, $gam->id, '12345', $admin)->id);
        $this->assertSame(1, $app->reportBindings()->count());
        Http::fake(fn () => Http::response($this->csv()));
        $firstImport = $this->import($binding);
        $this->assertSame('COMPLETED', $firstImport->status->value, $firstImport->error_message ?? '');
        $secondImport = $this->import($binding);
        $this->assertSame('COMPLETED', $secondImport->status->value, $secondImport->error_message ?? '');
        $this->assertSame(1, DailyReport::withoutGlobalScopes()->count());
        $this->assertSame(10000, (int) DailyReport::withoutGlobalScopes()->sum('gross_revenue_minor'));
    }

    public function test_sdk_path_duplicates_are_scoped_to_the_format_on_create_and_edit(): void
    {
        [$admin, $publisher, $user, $app, $gam] = $this->context();
        $app->update(['gam_connection_id' => $gam->id]);
        $this->actingAs($admin);
        $input = ['name' => 'Banner', 'format' => 'banner', 'ad_unit_path' => '/'.$gam->network_code.'/shared', 'is_enabled' => 1];
        $this->post(route('admin.mobile-apps.units', $app), $input)->assertSessionHasNoErrors();
        $banner = $app->adUnits()->firstOrFail();
        $this->post(route('admin.mobile-apps.units', $app), $input)->assertSessionHasErrors('ad_unit_path');
        $this->assertSame(1, $app->adUnits()->count());
        $this->post(route('admin.mobile-apps.units', $app), array_replace($input, [
            'id' => $banner->id, 'name' => 'Renamed banner', 'is_enabled' => 0,
        ]))->assertSessionHasNoErrors();
        $this->assertSame('Renamed banner', $banner->fresh()->name);
        $this->assertFalse($banner->fresh()->is_enabled);

        $interstitialInput = array_replace($input, ['name' => 'Interstitial', 'format' => 'interstitial']);
        $this->post(route('admin.mobile-apps.units', $app), $interstitialInput)->assertSessionHasNoErrors();
        $interstitial = $app->adUnits()->where('format', 'interstitial')->firstOrFail();
        $this->post(route('admin.mobile-apps.units', $app), $input + ['id' => $interstitial->id])->assertSessionHasErrors('ad_unit_path');
        $this->assertSame('interstitial', $interstitial->fresh()->format);

        $otherInput = array_replace($input, ['ad_unit_path' => '/'.$gam->network_code.'/other']);
        $this->post(route('admin.mobile-apps.units', $app), $otherInput)->assertSessionHasNoErrors();
        $other = $app->adUnits()->where('ad_unit_path', $otherInput['ad_unit_path'])->firstOrFail();
        $this->post(route('admin.mobile-apps.units', $app), $input + ['id' => $other->id])->assertSessionHasErrors('ad_unit_path');
        $this->assertSame($otherInput['ad_unit_path'], $other->fresh()->ad_unit_path);
        $this->assertSame(3, $app->adUnits()->count());
    }

    public function test_sdk_path_reuse_preserves_network_and_app_boundaries(): void
    {
        [$admin, $publisher, $user, $app, $gam] = $this->context();
        $app->update(['gam_connection_id' => $gam->id]);
        $this->actingAs($admin);
        $input = ['name' => 'Shared banner', 'format' => 'banner', 'ad_unit_path' => '/'.$gam->network_code.'/shared', 'is_enabled' => 1];
        $this->post(route('admin.mobile-apps.units', $app), $input)->assertSessionHasNoErrors();
        $unit = $app->adUnits()->firstOrFail();
        $this->post(route('admin.mobile-apps.units', $app), array_replace($input, [
            'format' => 'native', 'ad_unit_path' => '/'.$gam->network_code.'0/shared',
        ]))->assertSessionHasErrors('ad_unit_path');
        $other = app(MobileAppService::class)->create($publisher, array_replace($this->input(), [
            'store_url' => 'https://play.google.com/store/apps/details?id=com.horus.other',
        ]), $user);
        $other->update(['gam_connection_id' => $gam->id]);
        $this->post(route('admin.mobile-apps.units', $other), $input + ['id' => $unit->id])->assertNotFound();
        $this->assertSame($app->id, $unit->fresh()->mobile_app_id);
        $this->assertSame(0, $other->adUnits()->count());
        $this->post(route('admin.mobile-apps.units', $other), $input)->assertSessionHasNoErrors();
        $this->assertSame(1, $other->adUnits()->count());
        $this->actingAs($user)->post(route('admin.mobile-apps.units', $app), array_replace($input, ['format' => 'native']))->assertForbidden();
        $this->assertSame(1, $app->adUnits()->count());
    }

    public function test_scheduler_backoff_and_finalized_coverage_include_zero_days(): void
    {
        [$admin, $publisher, $user, $app, $gam] = $this->context();
        $binding = app(MobileAppReporting::class)->bind($app, $gam->id, '12345', $admin);
        Http::fake(fn () => Http::response($this->csv([])));
        $jobs = app(MobileAppReportSynchronizer::class)->sync($binding);
        $this->assertCount(2, $jobs);
        $this->assertSame(['COMPLETED', 'COMPLETED'], array_map(fn ($job) => $job->status->value, $jobs), json_encode(array_map(fn ($job) => $job->error_message, $jobs)));
        $this->assertSame(11, DailyReport::withoutGlobalScopes()->count());
        $this->assertCount(0, app(MobileAppReportSynchronizer::class)->sync($binding->fresh()));
        $period = app(FinancialPeriodService::class)->periodFor('2026-10-01', 'USD');
        $this->assertCount(1, app(MobileAppFinancialCoverage::class)->blockers($period));
        $binding->update(['ends_on' => '2026-10-10']);
        $this->assertCount(0, app(MobileAppFinancialCoverage::class)->blockers($period));
        $binding->connection->update(['is_enabled' => false]);
        DailyReport::withoutGlobalScopes()->whereDate('report_date', '2026-10-05')->delete();
        $this->assertCount(1, app(MobileAppFinancialCoverage::class)->blockers($period));
    }

    public function test_duplicate_app_cannot_register_through_another_android_store(): void
    {
        [$admin, $publisher, $user, $app] = $this->context();
        $data = $this->input(); $data['store_url'] = 'https://galaxystore.samsung.com/detail/com.horus.reader';
        $this->expectException(ValidationException::class);
        app(MobileAppService::class)->create($publisher, $data, $user);
    }

    public function test_current_day_is_estimated_and_closed_period_is_not_rewritten(): void
    {
        [$admin, $publisher, $user, $app, $gam] = $this->context();
        $binding = app(MobileAppReporting::class)->bind($app, $gam->id, '12345', $admin);
        Http::fake(fn () => Http::response($this->csv([])));
        $this->assertSame('FAILED', $this->import($binding, '2026-10-11')->status->value);
        $job = app(ReportImportService::class)->runConnection($binding->connection, now()->startOfDay(), now()->endOfDay(), ReportGranularity::Daily, ReportFinality::Estimated);
        $this->assertSame('COMPLETED', $job->status->value, $job->error_message ?? '');
        $this->assertFalse(DailyReport::withoutGlobalScopes()->firstOrFail()->settlement_eligible);
        $period = app(FinancialPeriodService::class)->periodFor('2026-10-10', 'USD');
        $period->update(['status' => 'CLOSED']);
        $this->assertSame('BLOCKED_CLOSED_PERIOD', $this->import($binding)->status->value);
        $this->assertSame(1, DailyReport::withoutGlobalScopes()->count());
    }

    public function test_generic_network_reports_cannot_double_count_app_revenue(): void
    {
        [$admin, $publisher, $user, $app, $gam] = $this->context();
        app(MobileAppReporting::class)->bind($app, $gam->id, '12345', $admin);
        $source = ReportSource::where('code', 'HORUS_GAM')->firstOrFail();
        $connection = ReportSourceConnection::withoutGlobalScopes()->create(['organization_id' => $publisher->organization_id,
            'report_source_id' => $source->id, 'name' => 'Legacy network source', 'connection_type' => 'GAM_CONNECTION',
            'connection_id' => $gam->id, 'currency' => 'USD', 'timezone' => 'UTC', 'status' => 'ACTIVE', 'is_enabled' => true]);
        $row = ['date' => '2026-10-10', 'organization_id' => $publisher->organization_id, 'publisher_id' => $publisher->id,
            'gam_connection_id' => $gam->id, 'gam_ad_unit_id' => '12345', 'currency' => 'USD', 'gross_revenue_minor' => 10000];
        $job = app(ReportImportService::class)->importRows($connection, [$row], ReportGranularity::Daily, ReportFinality::Finalized,
            CarbonImmutable::parse('2026-10-10'), CarbonImmutable::parse('2026-10-10'), importType: 'API');
        $this->assertSame('COMPLETED', $job->status->value); $this->assertSame(0, $job->row_count);
        unset($row['gam_ad_unit_id']);
        $job = app(ReportImportService::class)->importRows($connection, [$row], ReportGranularity::Daily, ReportFinality::Finalized,
            CarbonImmutable::parse('2026-10-10'), CarbonImmutable::parse('2026-10-10'), importType: 'API');
        $this->assertSame(0, $job->row_count); $this->assertSame(0, DailyReport::withoutGlobalScopes()->count());
    }

    public function test_website_dimension_hash_does_not_change_when_app_column_is_absent(): void
    {
        [$admin, $publisher, $user, $app] = $this->context();
        $row = ['publisher_id' => $publisher->id];
        $dimension = app(ReportDimensionResolver::class)->resolve($row, $publisher->organization_id);
        $payload = array_fill_keys(['publisher_id', 'site_id', 'placement_id', 'gam_connection_id', 'demand_network_id', 'bidder_id',
            'advertiser_id', 'campaign_id', 'country_code', 'device', 'browser', 'operating_system', 'ad_size'], null);
        $payload['publisher_id'] = $publisher->id; $payload['external_dimensions'] = []; ksort($payload);
        $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), $dimension->dimension_hash);
    }

    public function test_store_identity_is_permanent(): void
    {
        [$admin, $publisher, $user, $app] = $this->context();
        $this->expectException(\LogicException::class);
        $app->update(['store_app_id' => 'com.other.app']);
    }
}
