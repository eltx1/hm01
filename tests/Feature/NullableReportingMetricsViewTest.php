<?php

namespace Tests\Feature;

use App\Enums\{OrganizationType, RoleName};
use App\Services\Reporting\PerformanceMetrics;
use Carbon\CarbonImmutable;
use Database\Seeders\{InventoryDeliverySeeder, ReportingSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Illuminate\View\View as ViewInstance;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\{InteractsWithIdentity, InteractsWithPublisherSites};
use Tests\TestCase;

class NullableReportingMetricsViewTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public static function projectedCounters(): array
    {
        return [
            'unavailable legacy or mixed period' => [null, '—', 'Unavailable'],
            'verified zero' => [0, '0', '0'],
            'verified positive' => [1234, '1,234', '1,234'],
        ];
    }

    #[DataProvider('projectedCounters')]
    public function test_real_reporting_views_preserve_projected_null_and_verified_counters(
        ?int $counter,
        string $adminDisplay,
        string $publisherDisplay,
    ): void {
        $this->travelTo(CarbonImmutable::parse('2026-09-21 12:00:00', 'UTC'));
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, ReportingSeeder::class]);
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($user);
        $site = $this->makeSiteFor($publisher, $user);

        // Inject service output at the view boundary: this suite verifies display
        // independently of the report projection and stored financial records.
        $metrics = array_replace(array_fill_keys(array_keys(PerformanceMetrics::COLUMNS), null), [
            'impressions' => $counter, 'clicks' => $counter, 'metric_basis_incomplete' => $counter === null,
        ]);
        $daily = collect([['date' => '2026-09-20', ...$metrics, 'gross_revenue_minor' => 12345]]);
        $groups = collect([['label' => 'Synthetic reporting source', ...$metrics, 'gross_revenue_minor' => 12345]]);
        $this->patchView('admin.reporting.index', 'summary', [
            'available' => true, 'metric_basis_incomplete' => $counter === null, 'performance' => $metrics,
            'managed_impressions' => $counter, 'horus_gam_impressions' => $counter,
            'gross_revenue_minor' => 12345, 'net_revenue_minor' => 12345,
            'publisher_earnings_minor' => 8642, 'horus_margin_minor' => 3703,
            'daily_revenue' => $daily, 'revenue_by_publisher' => $groups,
            'revenue_by_website' => $groups, 'revenue_by_source' => $groups, 'revenue_by_campaign' => $groups,
        ]);
        $this->patchView('admin.reporting.website', 'summary', [
            ...$metrics, 'available' => true, 'gross_revenue_minor' => 12345,
            'publisher_earnings_minor' => 8642, 'horus_earnings_minor' => 3703, 'days' => $daily,
        ]);
        $this->patchView('admin.publishers.show', 'reporting', [
            'impressions' => $counter, 'metric_basis_incomplete' => $counter === null, 'revenue_minor' => 8642,
        ]);
        $this->patchView('dashboards.admin', 'reporting', [
            'managed_impressions' => $counter, 'metric_basis_incomplete' => $counter === null,
            'gross_revenue_minor' => 12345,
        ]);
        View::composer('admin.sites.gam-reporting', function (ViewInstance $view) use ($counter): void {
            $view->with('todayReport', [
                'available' => true, 'date' => '2026-09-21', 'timezone' => 'UTC', 'currency' => 'USD',
                'ad_requests' => $counter, 'impressions' => $counter, 'clicks' => $counter,
                'metric_basis_incomplete' => $counter === null, 'gross_revenue_minor' => 12345,
                'publisher_earnings_minor' => 8642, 'updated_at' => '2026-09-21 12:00:00', 'refresh_enabled' => true,
            ]);
        });
        $today = [
            'today_available' => true, 'today_impressions' => $counter, 'today_clicks' => $counter,
            'today_metric_basis_incomplete' => $counter === null, 'today_estimated_earnings_minor' => 8642,
        ];
        View::composer('dashboards.publisher', function (ViewInstance $view) use ($counter, $today): void {
            $reporting = $view->getData()['reporting'];
            $view->with('reporting', array_replace($reporting, [
                'impressions' => $counter, 'metric_basis_incomplete' => $counter === null,
                'primary' => array_replace($reporting['primary'], $today),
            ]));
        });
        View::composer('publisher.finance.overview', function (ViewInstance $view) use ($today): void {
            $view->with('currencies', $view->getData()['currencies']->map(
                fn (array $currency): array => array_replace($currency, $today),
            ));
        });

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        $overview = $this->get(route('admin.reporting.index'))->assertOk();
        $this->assertMetric($overview, 'Managed impressions', $adminDisplay);
        $this->assertMetric($overview, 'Net revenue', '123.45 USD');
        $overview->assertSee($adminDisplay.' impressions')->assertSee('Horus GAM impressions: '.$adminDisplay);
        $this->assertSame($counter === null, str_contains($overview->getContent(), 'periods containing legacy website GAM counters'));
        $this->fixture('admin-reports', $overview, $counter);

        $website = $this->get(route('admin.reporting.websites.show', $site))->assertOk();
        $this->assertMetric($website, 'Impressions', $adminDisplay);
        $this->assertMetric($website, 'Gross revenue', '123.45 USD');
        $this->fixture('admin-website', $website, $counter);

        $account = $this->get(route('admin.publishers.show', $publisher))->assertOk();
        $this->assertMetric($account, 'Impressions', $adminDisplay);
        $this->assertMetric($account, 'Publisher earnings', '86.42 USD');
        $this->fixture('admin-publisher', $account, $counter);

        $dashboard = $this->get('/')->assertOk();
        $this->assertMetric($dashboard, 'Managed impressions', $adminDisplay);
        $this->assertMetric($dashboard, 'Gross revenue · USD', '123.45 USD');
        $this->fixture('admin-dashboard', $dashboard, $counter);

        $todayReport = $this->get(route('admin.sites.show', $site))->assertOk();
        foreach (['Ad requests', 'Impressions', 'Clicks'] as $label) {
            $this->assertMetric($todayReport, $label, $adminDisplay);
        }
        $this->assertMetric($todayReport, 'Estimated gross revenue', '123.45 USD');
        $this->fixture('admin-site', $todayReport, $counter);

        $this->actingAs($user);
        $publisherDashboard = $this->get('/')->assertOk();
        $this->assertMetric($publisherDashboard, 'Impressions this month', $publisherDisplay);
        $this->assertMetric($publisherDashboard, 'Today · estimated', 'USD 86.42');
        $publisherDashboard->assertSee($counter === null ? 'Impressions unavailable' : $publisherDisplay.' impressions so far')
            ->assertDontSee('legacy website GAM counters');
        $this->fixture('publisher-dashboard', $publisherDashboard, $counter);

        $finance = $this->get(route('publisher.finance.overview'))->assertOk();
        $this->assertMetric($finance, 'Impressions', $publisherDisplay);
        $this->assertMetric($finance, 'Clicks', $publisherDisplay);
        $this->assertMetric($finance, 'Estimated earnings', 'USD 86.42');
        $finance->assertDontSee('legacy website GAM counters');
        $this->fixture('publisher-finance', $finance, $counter);
    }

    private function patchView(string $name, string $key, array $values): void
    {
        View::composer($name, function (ViewInstance $view) use ($key, $values): void {
            $view->with($key, array_replace($view->getData()[$key], $values));
        });
    }

    private function assertMetric(TestResponse $response, string $label, string $expected): void
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($dom);
        $value = $xpath->query('//*[self::p or self::h3 or self::span][normalize-space(.)="'.$label.'"]'
            .'/following-sibling::*[contains(@class, "metric") or contains(@class, "report-kpi-value")][1]')->item(0);
        $this->assertNotNull($value, 'Missing metric card: '.$label);
        $this->assertSame($expected, trim(preg_replace('/\s+/u', ' ', $value->textContent)), $label);
    }

    private function fixture(string $name, TestResponse $response, ?int $counter): void
    {
        if ($counter !== null || getenv('HORUS_UI_FIXTURES') !== '1') {
            return;
        }
        $directory = storage_path('framework/testing/nullable-reporting');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $html = str_replace('</head>', '<link rel="stylesheet" href="/fixture.css"></head>', $response->getContent());
        $html = str_replace('</body>', '<script type="module" src="/fixture.js"></script></body>', $html);
        file_put_contents($directory.'/'.$name.'.html', $html);
    }
}
