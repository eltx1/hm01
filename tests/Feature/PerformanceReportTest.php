<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\SiteConfig;
use App\Services\Diagnostics\PerformanceReport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PerformanceReportTest extends TestCase
{
    private string $credential;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'diagnostics.account_id' => str_repeat('a', 32)]);
        Cache::flush();
        $this->credential = tempnam(sys_get_temp_dir(), 'diagnostic-test-');
        file_put_contents($this->credential, 'PRIVATE_TEST_CREDENTIAL');
        config(['diagnostics.api_token_reference' => 'file:'.$this->credential]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        @unlink($this->credential);
        parent::tearDown();
    }

    private function site(int $version = 7, string $key = 'hm_test_metrics'): Site
    {
        return (new Site(['public_key' => $key]))->setRelation('siteConfig', new SiteConfig(['production_version' => $version]));
    }

    private function row(array $overrides = []): array
    {
        return array_replace(['device' => 'android', 'browser' => 'chrome', 'metric' => 'first_ad',
            'stored_rows' => '4', 'samples' => '8', 'estimated_events' => '800',
            'p50_ms' => '1200', 'p75_ms' => '1800', 'p95_ms' => '2500'], $overrides);
    }

    public function test_read_is_aggregate_bounded_version_scoped_and_cached_without_private_fields(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response(['data' => [$this->row(['url' => 'PRIVATE_VISITOR_URL'])]])]);
        $reader = app(PerformanceReport::class);
        $result = $reader->forSite($this->site());
        $this->assertSame('AVAILABLE', $result['state']);
        $this->assertSame(7, $result['configuration_version']);
        $this->assertSame(4.0, $result['rows'][0]['stored_rows']);
        $this->assertSame(8.0, $result['rows'][0]['samples']);
        $this->assertSame(800.0, $result['rows'][0]['estimated_events']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($result));
        $this->assertSame($result, $reader->forSite($this->site()));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://api.cloudflare.com/client/v4/accounts/'.str_repeat('a', 32).'/analytics_engine/sql'
            && str_contains($request->body(), "index1 = 'hm_test_metrics' AND blob5 = '7'")
            && str_contains($request->body(), 'quantileExactWeighted(0.95)(double1, _sample_interval)')
            && str_contains($request->body(), "INTERVAL '1' DAY") && str_ends_with($request->body(), 'LIMIT 200'));
        $reader->forSite($this->site(8));
        Http::assertSentCount(2);
    }

    public function test_absence_is_not_reported_as_zero_latency(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response(['data' => []])]);
        $result = app(PerformanceReport::class)->forSite($this->site());
        $this->assertSame('NO_OBSERVATIONS', $result['state']);
        $this->assertSame([], $result['rows']);
    }

    public function test_missing_setup_unconfirmed_version_and_unsafe_keys_never_query(): void
    {
        $reader = app(PerformanceReport::class);
        $this->assertSame('NO_CONFIRMED_VERSION', $reader->forSite($this->site(0))['state']);
        $this->assertSame('UNAVAILABLE', $reader->forSite($this->site(7, "hm_' OR 1=1"))['state']);
        config(['diagnostics.account_id' => 'not-account', 'static-delivery.cloudflare.account_id' => null]);
        $this->assertSame('SETUP_REQUIRED', $reader->forSite($this->site())['state']);
        Http::assertNothingSent();
    }

    public function test_upstream_refusal_and_private_exceptions_stay_unavailable_without_leaking(): void
    {
        foreach ([403, 429, 500] as $status) {
            Cache::flush();
            Http::fake(['api.cloudflare.com/*' => Http::response(['error' => 'PRIVATE_ERROR'], $status)]);
            $result = app(PerformanceReport::class)->forSite($this->site());
            $this->assertSame('UNAVAILABLE', $result['state']);
            $this->assertStringNotContainsString('PRIVATE', json_encode($result));
        }
        Cache::flush();
        Http::fake(fn () => throw new \RuntimeException('PRIVATE_TRANSPORT_EXCEPTION'));
        $this->assertSame('UNAVAILABLE', app(PerformanceReport::class)->forSite($this->site())['state']);
    }

    public function test_invalid_oversized_or_unexpected_aggregate_data_is_rejected(): void
    {
        foreach ([['data' => null], ['data' => array_fill(0, 201, $this->row())],
            ['data' => [$this->row(['device' => 'PRIVATE_RAW_AGENT'])]],
            ['data' => [$this->row(['p95_ms' => 'NaN'])]], ['data' => [$this->row(['p50_ms' => -1])]],
            ['data' => [$this->row(['p95_ms' => 60001])]], ['data' => [$this->row(['metric' => 'visitor_url'])]],
            ['data' => [$this->row(['stored_rows' => null])]], ['data' => [], 'oversize' => str_repeat('x', 262145)]] as $body) {
            Cache::flush();
            Http::fake(['api.cloudflare.com/*' => Http::response($body)]);
            $result = app(PerformanceReport::class)->forSite($this->site());
            $this->assertSame('UNAVAILABLE', $result['state']);
            $this->assertSame([], $result['rows']);
        }
    }

    public function test_unreadable_credential_is_redacted_and_does_not_call_api(): void
    {
        config(['diagnostics.api_token_reference' => 'file:/missing/PRIVATE_TOKEN_FILE']);
        $result = app(PerformanceReport::class)->forSite($this->site());
        $this->assertSame('UNAVAILABLE', $result['state']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($result));
        Http::assertNothingSent();
    }
}
