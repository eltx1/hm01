<?php

namespace Tests\Feature;

use App\Enums\{OrganizationType, ReportFinality, ReportGranularity, ReportImportStatus, ReportSourceCode, RevenueRuleScope, RoleName};
use App\Models\{DailyReport, FinancialPeriod, MonthlyReport, PublisherStatement, ReportDimension, ReportSource, ReportSourceConnection};
use App\Services\Reporting\{FinancialPeriodService, PerformanceMetrics, PublisherPerformanceService, ReportImportService, RevenueRuleService, SiteGamReportMetrics, UnifiedReportService};
use Carbon\CarbonImmutable;
use Database\Seeders\{InventoryDeliverySeeder, ReportingSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\{InteractsWithIdentity, InteractsWithPublisherSites};
use Tests\TestCase;

class ReportPerformanceMetricsTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private function context(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21 12:00:00'));
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, ReportingSeeder::class]);
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($user);
        $site = $this->makeSiteFor($publisher, $user, ['display_name' => 'Example publishing']);
        $source = ReportSource::where('code', ReportSourceCode::HorusGam->value)->firstOrFail();
        $connection = ReportSourceConnection::withoutGlobalScopes()->create([
            'organization_id' => $admin->organization_id, 'report_source_id' => $source->id,
            'name' => 'Performance import', 'connection_type' => 'TEST', 'connection_id' => 'performance-test',
            'currency' => 'USD', 'timezone' => 'UTC', 'status' => 'ACTIVE', 'is_enabled' => true,
        ]);

        return [$admin, $user, $publisher, $site, $connection];
    }

    private function import(array $context, array $metrics, string $date = '2026-08-01', ReportFinality $finality = ReportFinality::Finalized)
    {
        [$admin, , $publisher, $site, $connection] = $context;
        $day = CarbonImmutable::parse($date);

        return app(ReportImportService::class)->importRows($connection, [[
            'date' => $date, 'site_id' => $site->id, 'publisher_id' => $publisher->id,
            'currency' => 'USD', ...$metrics,
        ]], ReportGranularity::Daily, $finality, $day, $day, $admin);
    }

    public function test_weighted_metrics_use_historical_publisher_allocations_and_survive_financial_close(): void
    {
        $context = [$admin, $user, $publisher, $site] = $this->context();
        $rules = app(RevenueRuleService::class);
        $rule = $rules->createRule([
            'name' => 'Historical performance share', 'scope_type' => RevenueRuleScope::Website,
            'scope_id' => $site->id, 'effective_from' => '2026-08-01',
            'publisher_share_bp' => 8000, 'horus_share_bp' => 2000, 'mcm_partner_share_bp' => 0,
        ], $admin);
        $rules->changeRule($rule, ['effective_from' => '2026-08-02', 'publisher_share_bp' => 6000,
            'horus_share_bp' => 4000, 'mcm_partner_share_bp' => 0, 'reason' => 'New commercial agreement'], $admin);
        foreach ([
            ['2026-08-01', 100, 10, 10000, 9, 10, 7],
            ['2026-08-02', 900, 0, 20000, 90, 900, 13],
        ] as [$date, $impressions, $clicks, $gross, $viewable, $measurable, $unfilled]) {
            $job = $this->import($context, ['impressions' => $impressions, 'clicks' => $clicks,
                'gross_revenue_minor' => $gross, 'unfilled_requests' => 100,
                'active_view_viewable_impressions' => $viewable, 'active_view_measurable_impressions' => $measurable,
                'unfilled_impressions' => $unfilled], $date);
            $this->assertSame(ReportImportStatus::Completed, $job->status, $job->error_message ?? '');
        }
        $performance = app(PublisherPerformanceService::class)->summary($publisher, '2026-08-01', '2026-08-31');
        $this->assertSame(20000, $performance['earnings_minor']); // 80% of $100 + 60% of $200.
        $this->assertSame(20000, $performance['ecpm_minor']);
        $this->assertSame(100, $performance['ctr_bp']); // 10 / 1000; not the average daily CTR.
        $this->assertSame(1088, $performance['viewability_bp']); // 99 / 910; not weighted by served impressions.
        $this->assertSame(20, $performance['unfilled_impressions']);
        $this->assertArrayNotHasKey('gross_revenue_minor', $performance);
        $staff = app(UnifiedReportService::class)->adminSummary('2026-08-01', '2026-08-31');
        $this->assertSame(30000, $staff['performance']['ecpm_minor']);
        $this->assertSame($performance['viewability_bp'], $staff['performance']['viewability_bp']);

        $period = FinancialPeriod::where('period_key', '2026-08')->where('currency', 'USD')->firstOrFail();
        app(FinancialPeriodService::class)->close($period, $admin, 'Performance test fixture close');
        $month = MonthlyReport::withoutGlobalScopes()->sole();
        $this->assertSame(20000, (int) $month->publisher_earnings_minor);
        $this->assertSame(99, (int) $month->active_view_viewable_impressions);
        $this->assertSame(910, (int) $month->active_view_measurable_impressions);
        $this->assertSame(1088, (int) $month->viewability_bp);
        $statement = PublisherStatement::withoutGlobalScopes()->where('publisher_id', $publisher->id)->sole();
        $before = $statement->getAttributes();
        $this->assertSame(20000, (int) $statement->publisher_earnings_minor);
        $this->assertSame(ReportImportStatus::BlockedClosedPeriod, $this->import($context, ['unfilled_impressions' => 123])->status);
        $this->assertSame($before, $statement->fresh()->getAttributes());
    }

    public function test_new_counters_update_the_same_dimension_without_duplicating_revenue_and_validate_atomically(): void
    {
        $context = $this->context();
        $base = ['impressions' => 100, 'clicks' => 2, 'gross_revenue_minor' => 1000];
        $this->assertSame(ReportImportStatus::Completed, $this->import($context, $base)->status);
        $dimension = DailyReport::withoutGlobalScopes()->sole()->report_dimension_id;
        $metrics = [...$base, 'active_view_viewable_impressions' => 30, 'active_view_measurable_impressions' => 40, 'unfilled_impressions' => 17];
        $this->assertSame(ReportImportStatus::Completed, $this->import($context, $metrics)->status);
        $row = DailyReport::withoutGlobalScopes()->sole();
        $this->assertSame($dimension, $row->report_dimension_id);
        $this->assertSame(1000, (int) $row->gross_revenue_minor);
        $this->assertSame(700, (int) $row->publisher_earnings_minor);
        $this->assertSame(7500, (int) $row->viewability_bp);
        $before = $row->getAttributes();
        foreach ([['unfilled_impressions' => -1], ['active_view_viewable_impressions' => 41],
            ['active_view_measurable_impressions' => null], ['unfilled_impressions' => '1.5']] as $invalid) {
            $this->assertSame(ReportImportStatus::Failed, $this->import($context, array_replace($metrics, $invalid))->status);
            $this->assertSame($before, $row->fresh()->getAttributes());
        }
    }

    public function test_missing_data_zero_denominators_and_mixed_source_coverage_are_not_fabricated(): void
    {
        $metrics = new PerformanceMetrics;
        $known = ['impressions' => 0, 'clicks' => 0, 'publisher_earnings_minor' => 0,
            'active_view_viewable_impressions' => 0, 'active_view_measurable_impressions' => 0, 'unfilled_impressions' => 0];
        $zero = $metrics->summarize(collect([$known]), 'publisher_earnings_minor');
        $this->assertNull($zero['viewability_bp']);
        $this->assertNull($zero['ctr_bp']);
        $this->assertNull($zero['ecpm_minor']);
        $this->assertSame(0, $zero['unfilled_impressions']);
        $mixed = $metrics->summarize(collect([$known, ['impressions' => 100, 'viewability_bp' => 5000]]), 'publisher_earnings_minor');
        $this->assertNull($mixed['viewability_bp']);
        $this->assertNull($mixed['unfilled_impressions']);
    }

    public function test_custom_columns_and_csv_are_publisher_scoped_and_render_real_pages_for_both_themes(): void
    {
        $context = [$admin, $user, $publisher, $site] = $this->context();
        $this->assertSame(ReportImportStatus::Completed, $this->import($context, [
            'impressions' => 1000, 'clicks' => 20, 'gross_revenue_minor' => 10000,
            'active_view_viewable_impressions' => 300, 'active_view_measurable_impressions' => 500, 'unfilled_impressions' => 25,
        ], '2026-09-20')->status);
        $otherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $other = $this->makePublisherFor($otherUser);
        $otherSite = $this->makeSiteFor($other, $otherUser, ['display_name' => 'PRIVATE OTHER PUBLISHER']);
        $otherContext = [$admin, $otherUser, $other, $otherSite, $context[4]];
        $this->import($otherContext, ['impressions' => 999999, 'gross_revenue_minor' => 99999900], '2026-09-20');
        $this->actingAs($user);
        foreach (['publisher.reporting.index', 'publisher.finance.overview'] as $route) {
            $response = $this->get(route($route))->assertOk()->assertSee('Active View')->assertSee('60.00%')
                ->assertSee('Unfilled impressions')->assertSee('Customize columns')->assertDontSee('PRIVATE OTHER PUBLISHER')
                ->assertDontSee('Gross revenue')->assertDontSee('Horus margin')->assertDontSee('gross_revenue_minor');
            if ($route === 'publisher.reporting.index') {
                $response->assertDontSee('Your revenue share is already applied.')->assertDontSee('About these numbers')->assertDontSee('Paid to date')
                    ->assertDontSee('Live estimate')->assertDontSee('USD 100.00');
                $this->fixture('reports-publisher', $response);
                $selected = $this->get(route($route, ['customize' => 1, 'metrics' => ['clicks', 'viewability_bp']]))
                    ->assertOk()->assertSee('60.00%')->assertDontSee('Gross revenue')->assertDontSee('PRIVATE OTHER PUBLISHER');
                $this->fixture('reports-publisher-selected', $selected);
            }
            $csv = $this->get(route($route, ['export' => 'csv', 'metrics' => ['clicks', 'ecpm_minor', 'viewability_bp']]))->assertOk()->streamedContent();
            $this->assertStringContainsString('Publisher earnings', $csv);
            $this->assertStringContainsString('70.00', $csv);
            $this->assertStringContainsString('60.00%', $csv);
            $this->assertStringNotContainsString('Gross revenue', $csv);
            $this->assertStringNotContainsString('Horus margin', $csv);
            $this->assertStringNotContainsString('999999', $csv);
            $this->assertStringNotContainsString('Unfilled impressions', $csv);
            $this->get(route($route, ['metrics' => ['gross_revenue_minor']]))->assertSessionHasErrors('metrics.0');
            $this->get(route($route, ['customize' => 1]))->assertSessionHasErrors('metrics');
        }
        $this->get(route('admin.reporting.index'))->assertForbidden();
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        $response = $this->get(route('admin.reporting.index'))->assertOk()->assertSee('Active View')->assertSee('Unfilled impressions');
        $this->fixture('reports-admin', $response);
        $csv = $this->get(route('admin.reporting.index', ['export' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Gross revenue', $csv);
        $this->assertStringNotContainsString('Publisher earnings', $csv);
    }

    public function test_publisher_report_explains_estimates_missing_and_zero_metrics_without_leaking_gross(): void
    {
        $context = [$admin, $user, $publisher] = $this->context();
        $this->actingAs($user);
        $response = $this->get(route('publisher.reporting.index'))->assertOk()
            ->assertSee('No reports for these dates yet')->assertDontSee('Gross revenue');
        $this->fixture('reports-publisher-empty', $response);

        $this->import($context, ['impressions' => 1000, 'clicks' => 20, 'gross_revenue_minor' => 10000,
            'active_view_viewable_impressions' => 300, 'active_view_measurable_impressions' => 500,
            'unfilled_impressions' => 25], '2026-09-19');
        $this->import($context, ['impressions' => 500, 'clicks' => 5, 'gross_revenue_minor' => 5000],
            '2026-09-20', ReportFinality::Estimated);
        $summary = app(PublisherPerformanceService::class)->summary($publisher, '2026-09-01', '2026-09-21');
        $this->assertSame(10500, $summary['earnings_minor']);
        $this->assertSame(7000, $summary['finalized_minor']);
        $this->assertSame(3500, $summary['estimated_minor']);
        $this->assertSame(7000, $summary['ecpm_minor']);
        $this->assertTrue($summary['has_estimates']);
        $response = $this->get(route('publisher.reporting.index'))->assertOk()
            ->assertSee('Includes estimates')->assertSee('Unavailable')->assertDontSee('Incomplete source data')
            ->assertSee('USD 70.00')->assertSee('USD 35.00')->assertDontSee('USD 150.00')
            ->assertDontSee('Gross revenue')->assertDontSee('Horus margin');
        $this->fixture('reports-publisher-mixed', $response);
        $csv = $this->get(route('publisher.reporting.index', ['export' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('70.00', $csv);
        $this->assertStringContainsString('35.00', $csv);
        $this->assertStringNotContainsString('100.00', $csv);
        $this->assertStringNotContainsString('50.00', $csv);
        $this->assertStringNotContainsString('Gross revenue', $csv);

        $this->import($context, ['impressions' => 0, 'clicks' => 0, 'gross_revenue_minor' => 0,
            'active_view_viewable_impressions' => 0, 'active_view_measurable_impressions' => 0,
            'unfilled_impressions' => 0], '2026-09-21', ReportFinality::Estimated);
        $response = $this->get(route('publisher.reporting.index', ['from' => '2026-09-21', 'to' => '2026-09-21']))
            ->assertOk()->assertSee('Includes estimates')->assertSee('Unavailable')
            ->assertDontSee('Incomplete source data');
        $this->fixture('reports-publisher-zero', $response);
    }

    public function test_report_and_finance_navigation_respects_independent_permissions(): void
    {
        [, $user] = $this->context();
        $role = $user->roles()->firstOrFail();
        $financePermission = \App\Models\Permission::where('name', 'finance.publisher.view_own')->firstOrFail();
        $reportPermission = \App\Models\Permission::where('name', 'reporting.publisher.view')->firstOrFail();
        $role->permissions()->detach($financePermission);
        $user = $user->fresh();
        $this->actingAs($user)->get(route('publisher.reporting.index'))->assertOk()
            ->assertDontSee('href="'.route('publisher.finance.overview').'"', false)
            ->assertDontSee('View statements');
        $this->get(route('publisher.finance.overview'))->assertForbidden();
        $nav = collect(app(\App\Services\ControlPlane\ControlPlaneNavigation::class)->for($user))->pluck('items')->flatten(1);
        $this->assertSame('publisher.reporting.index', $nav->firstWhere('label', 'Reports & earnings')['route']);

        $role->permissions()->attach($financePermission);
        $role->permissions()->detach($reportPermission);
        $user = $user->fresh();
        $this->actingAs($user)->get(route('publisher.finance.overview'))->assertOk()
            ->assertDontSee('href="'.route('publisher.reporting.index').'"', false);
        $this->get(route('publisher.reporting.index'))->assertForbidden();
        $nav = collect(app(\App\Services\ControlPlane\ControlPlaneNavigation::class)->for($user))->pluck('items')->flatten(1);
        $this->assertSame('publisher.finance.overview', $nav->firstWhere('label', 'Reports & earnings')['route']);
    }

    public function test_today_and_yesterday_shortcuts_preserve_columns_and_cross_month_boundaries(): void
    {
        [$admin, $user] = $this->context();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 00:05:00', config('app.timezone')));
        $metrics = ['clicks', 'ecpm_minor'];
        foreach (['publisher.reporting.index', 'publisher.finance.overview', 'admin.reporting.index'] as $route) {
            $this->actingAs($route === 'admin.reporting.index' ? $admin : $user)
                ->withSession(['two_factor_passed_at' => now()->timestamp]);
            $response = $this->get(route($route, ['metrics' => $metrics]))->assertOk();
            $dom = new \DOMDocument;
            @$dom->loadHTML($response->getContent());
            $xpath = new \DOMXPath($dom);
            foreach (['Today' => '2026-10-01', 'Yesterday' => '2026-09-30'] as $label => $date) {
                $link = $xpath->query('//nav[@aria-label="Quick reporting periods"]/a[normalize-space(.)="'.$label.'"]')->item(0);
                $this->assertNotNull($link);
                parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
                $this->assertSame($date, $query['from']);
                $this->assertSame($date, $query['to']);
                $this->assertSame($metrics, $query['metrics']);
                $this->assertSame(parse_url(route($route), PHP_URL_PATH), parse_url($link->getAttribute('href'), PHP_URL_PATH));
                $selected = $this->get($link->getAttribute('href'))->assertOk();
                $selectedDom = new \DOMDocument;
                @$selectedDom->loadHTML($selected->getContent());
                $selectedLink = (new \DOMXPath($selectedDom))->query('//nav[@aria-label="Quick reporting periods"]/a[normalize-space(.)="'.$label.'"]')->item(0);
                $this->assertSame('true', $selectedLink->getAttribute('aria-current'));
            }
        }
    }

    public function test_admin_website_reports_are_site_scoped_and_keep_csv_dates_metrics_and_permissions(): void
    {
        $context = [$admin, $user, $publisher, $site] = $this->context();
        $this->import($context, ['impressions' => 1000, 'clicks' => 20, 'gross_revenue_minor' => 10000,
            'active_view_viewable_impressions' => 300, 'active_view_measurable_impressions' => 500,
            'unfilled_impressions' => 25], '2026-09-20');
        $this->import($context, ['impressions' => 50, 'gross_revenue_minor' => 50000], '2026-09-21', ReportFinality::Estimated);
        $this->import($context, ['impressions' => 100, 'gross_revenue_minor' => 20000], '2026-08-20');
        $other = $this->makeSiteFor($publisher, $user, ['display_name' => 'Other website']);
        $otherContext = [$admin, $user, $publisher, $other, $context[4]];
        $this->import($otherContext, ['impressions' => 9999, 'gross_revenue_minor' => 90000], '2026-09-20');
        $empty = $this->makeSiteFor($publisher, $user, ['display_name' => 'New website']);
        $this->actingAs($user);
        $this->get(route('admin.reporting.websites.index'))->assertForbidden();
        $this->get(route('admin.reporting.websites.show', $site))->assertForbidden();
        $this->get(route('admin.reporting.websites.show', ['site' => $site, 'export' => 'csv']))->assertForbidden();
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        $this->fixture('workspace-admin-site', $this->get(route('admin.sites.show', $site))->assertOk()->assertSee(route('admin.reporting.websites.show', $site), false));
        $before = DailyReport::withoutGlobalScopes()->get()->map->getAttributes()->all();
        $response = $this->get(route('admin.reporting.websites.show', $site))->assertOk()
            ->assertSee('Gross revenue')->assertSee('Publisher earnings')->assertSee('Horus margin')
            ->assertSee('Includes estimates')->assertDontSee('Other website');
        $summary = $response->viewData('summary');
        $this->assertSame(60000, $summary['gross_revenue_minor']);
        $this->assertSame(42000, $summary['publisher_earnings_minor']);
        $this->assertSame(18000, $summary['horus_earnings_minor']);
        $this->assertSame(1050, $summary['impressions']);
        $this->assertSame(57143, $summary['ecpm_minor']);
        $this->assertTrue($summary['has_estimates']);
        $this->assertCount(2, $summary['days']);
        $this->fixture('reports-admin-website', $response);
        $csv = $this->get(route('admin.reporting.websites.show', ['site' => $site, 'from' => '2026-09-20',
            'to' => '2026-09-20', 'metrics' => ['clicks', 'viewability_bp'], 'export' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('2026-09-20,20,60.00%,100.00', $csv);
        $this->assertStringNotContainsString('Impressions', $csv);
        $this->assertStringNotContainsString('900.00', $csv);
        $response = $this->get(route('admin.reporting.websites.show', $empty))->assertOk()->assertSee('No reports for these dates');
        $this->fixture('reports-admin-website-empty', $response);
        $this->assertSame($before, DailyReport::withoutGlobalScopes()->get()->map->getAttributes()->all());
        $response = $this->get(route('admin.reporting.websites.index'))->assertOk()->assertSee('New website')->assertSee('Other website');
        $this->fixture('reports-admin-websites', $response);
        $this->get(route('admin.reporting.websites.index', ['q' => $site->primary_domain]))->assertOk()
            ->assertViewHas('sites', fn ($sites) => $sites->total() === 1 && $sites->first()->id === $site->id);
        $this->get(route('admin.reporting.websites.index', ['q' => $publisher->display_name]))->assertOk()
            ->assertViewHas('sites', fn ($sites) => $sites->total() === 3);
        $this->get(route('admin.reporting.websites.index', ['q' => ['invalid']]))->assertSessionHasErrors('q');
        $this->get(route('admin.reporting.websites.show', ['site' => $site, 'metrics' => ['invalid']]))->assertSessionHasErrors('metrics.0');
        $site->delete();
        $this->get(route('admin.reporting.websites.show', $site))->assertNotFound();
    }

    public function test_admin_website_directory_has_bounded_pagination_and_permission_checks(): void
    {
        [$admin, $user, $publisher] = $this->context();
        for ($i = 0; $i < 25; $i++) {
            $this->makeSiteFor($publisher, $user, ['display_name' => 'Website '.$i]);
        }
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        $this->get(route('admin.reporting.websites.index'))->assertOk()
            ->assertViewHas('sites', fn ($sites) => $sites->total() === 26 && $sites->count() === 24);
        $this->get(route('admin.reporting.websites.index', ['page' => 2]))->assertOk()
            ->assertViewHas('sites', fn ($sites) => $sites->count() === 2);
        $permission = \App\Models\Permission::where('name', 'reporting.admin.view')->firstOrFail();
        $admin->roles()->firstOrFail()->permissions()->detach($permission);
        $this->actingAs($admin->fresh());
        $this->get(route('admin.reporting.websites.index'))->assertForbidden();
        $this->get(route('admin.reporting.websites.show', \App\Models\Site::firstOrFail()))->assertForbidden();
    }

    public function test_admin_website_aggregation_preserves_weighted_metrics_and_unknown_counters(): void
    {
        $context = [$admin, $user, $publisher, $site] = $this->context();
        foreach ([['2026-09-19', 100, 9, 10], ['2026-09-20', 900, 90, 900]] as [$date, $impressions, $viewable, $measurable]) {
            $this->import($context, ['impressions' => $impressions, 'clicks' => 5, 'gross_revenue_minor' => 10000,
                'active_view_viewable_impressions' => $viewable, 'active_view_measurable_impressions' => $measurable,
                'unfilled_impressions' => 10], $date);
        }
        $this->actingAs($admin);
        $service = app(\App\Services\Reporting\AdminWebsitePerformanceService::class);
        $summary = $service->summary($site, '2026-09-01', '2026-09-21');
        $this->assertSame(1088, $summary['viewability_bp']);
        $this->assertSame(100, $summary['ctr_bp']);
        $this->assertSame(20000, $summary['ecpm_minor']);
        $this->assertCount(2, $summary['days']);
        $this->assertSame(20, $summary['unfilled_impressions']);
        $this->assertSame(1088, $service->summaries(collect([$site]), '2026-09-01', '2026-09-21')[$site->id]['viewability_bp']);
        DailyReport::query()->whereDate('report_date', '2026-09-19')->update(['unfilled_impressions' => null]);
        $summary = $service->summary($site, '2026-09-01', '2026-09-21');
        $this->assertNull($summary['unfilled_impressions']);
        $this->assertNull($summary['days'][0]['unfilled_impressions']);
        $this->assertSame(10, $summary['days'][1]['unfilled_impressions']);
        $this->assertNull($service->summaries(collect([$site]), '2026-09-01', '2026-09-21')[$site->id]['unfilled_impressions']);
    }

    private function fixture(string $name, \Illuminate\Testing\TestResponse $response): void
    {
        if (getenv('HORUS_UI_FIXTURES') !== '1') {
            return;
        }
        $directory = storage_path('framework/testing/form-experience');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $html = str_replace('</head>', '<link rel="stylesheet" href="/fixture.css"></head>', $response->getContent());
        $html = str_replace('</body>', '<script type="module" src="/fixture.js"></script></body>', $html);
        file_put_contents($directory.'/'.$name.'.html', $html);
    }

    public function test_optional_adx_column_failure_retries_core_columns_once_without_changing_currency_or_scope(): void
    {
        $client = \Mockery::mock(\App\Services\Reporting\GamAdUnitReportClient::class)->makePartial();
        $gam = new \App\Models\GamConnection;
        $query = ['reportCurrency' => 'USD', 'dimensions' => ['DATE', 'AD_UNIT_ID', 'SITE_NAME'],
            'statement' => ['query' => 'WHERE AD_UNIT_ID = :unit'],
            'columns' => array_keys(SiteGamReportMetrics::COLUMNS)];
        $client->shouldReceive('call')->once()->with($gam, 'ReportService', 'runReportJob', ['reportJob' => ['reportQuery' => $query]])
            ->andThrow(new \RuntimeException('ReportError.COLUMNS_NOT_SUPPORTED_FOR_REQUESTED_DIMENSIONS'));
        $base = $query;
        $base['columns'] = array_keys(SiteGamReportMetrics::CORE_COLUMNS);
        $client->shouldReceive('call')->once()->with($gam, 'ReportService', 'runReportJob', ['reportJob' => ['reportQuery' => $base]])
            ->andReturn(['id' => '77']);
        $this->assertSame(['id' => '77'], $client->runPerformanceReport($gam, $query, array_keys(SiteGamReportMetrics::OPTIONAL_COLUMNS)));
        $client = \Mockery::mock(\App\Services\Reporting\GamAdUnitReportClient::class)->makePartial();
        $client->shouldReceive('call')->once()->andThrow(new \RuntimeException('PERMISSION_DENIED'));
        $this->expectExceptionMessage('PERMISSION_DENIED');
        $client->runPerformanceReport($gam, $query, array_keys(SiteGamReportMetrics::OPTIONAL_COLUMNS));
    }

    public function test_network_wide_optional_column_fallback_keeps_the_existing_total_metric_family(): void
    {
        $client = \Mockery::mock(\App\Services\Reporting\GamAdUnitReportClient::class)->makePartial();
        $gam = new \App\Models\GamConnection;
        $query = ['reportCurrency' => 'USD', 'dimensions' => ['DATE', 'AD_UNIT_ID'],
            'columns' => array_keys(\App\Services\Reporting\Connectors\GamReportConnector::COLUMNS)];
        $this->assertContains('TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE', $query['columns']);
        $this->assertNotContains('AD_EXCHANGE_LINE_ITEM_LEVEL_REVENUE', $query['columns']);
        $client->shouldReceive('call')->once()->with($gam, 'ReportService', 'runReportJob', ['reportJob' => ['reportQuery' => $query]])
            ->andThrow(new \RuntimeException('ReportError.COLUMNS_NOT_SUPPORTED_FOR_REQUESTED_DIMENSIONS'));
        $base = $query;
        $base['columns'] = array_values(array_diff($query['columns'], array_keys(PerformanceMetrics::GOOGLE_COLUMNS)));
        $client->shouldReceive('call')->once()->with($gam, 'ReportService', 'runReportJob', ['reportJob' => ['reportQuery' => $base]])
            ->andReturn(['id' => '78']);

        $this->assertSame(['id' => '78'], $client->runPerformanceReport($gam, $query));
    }
}
