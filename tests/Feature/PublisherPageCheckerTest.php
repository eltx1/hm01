<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Services\Campaigns\RemoteUrlSafetyValidator;
use App\Services\Network\Contracts\DnsResolver;
use App\Services\Sites\PublisherPageChecker;
use App\Services\Sites\PublisherPageInspection;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class PublisherPageCheckerTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private function site(): array
    {
        $this->seedIdentity();
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($user), $user, ['primary_domain' => 'publisher.example.test']);
        return [$user, $site];
    }

    private function checker(array $responses, array &$history, bool $rebind = false): PublisherPageChecker
    {
        $dns = new class($rebind) implements DnsResolver {
            private int $calls = 0;
            public function __construct(private readonly bool $rebind) {}
            public function addresses(string $host): array { return ++$this->calls > 1 && $this->rebind ? ['127.0.0.1'] : ['8.8.8.8']; }
        };
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        return new PublisherPageChecker(new RemoteUrlSafetyValidator($dns), new PublisherPageInspection, new Client(['handler' => $stack]));
    }

    public function test_checks_html_without_executing_ads_and_audits_no_url_or_html(): void
    {
        [$user, $site] = $this->site(); $history = [];
        $html = '<script src="'.config('horus.loader_url').'" data-site-key="'.$site->public_key.'"></script>';
        $checker = $this->checker([new Response(200, ['Content-Type' => 'text/html'], $html)], $history);
        $this->app->instance(PublisherPageChecker::class, $checker);
        $this->actingAs($user)->post(route('publisher.sites.installation-check', $site), ['page_url' => 'https://publisher.example.test/page?private_parameter=do_not_record'])
            ->assertRedirect(route('publisher.sites.show', $site))->assertSessionHas('installation_check.code', 'CORRECT_TAG_FOUND');
        $this->assertCount(1, $history);
        $this->assertFalse($history[0]['options']['allow_redirects']);
        $this->assertSame(['publisher.example.test:443:8.8.8.8:443'], $history[0]['options']['curl'][CURLOPT_CONNECT_TO]);
        $this->assertStringNotContainsString('do_not_record', \App\Models\AuditLog::query()->get()->toJson());
        $this->assertStringNotContainsString('<script', json_encode(session('installation_check')));
    }

    public function test_redirects_cannot_reach_other_hosts_or_rebound_private_addresses(): void
    {
        [, $site] = $this->site(); $history = [];
        $result = $this->checker([new Response(302, ['Location' => 'http://127.0.0.1/private'])], $history)->check($site, 'https://publisher.example.test/');
        $this->assertSame('UNSAFE_REDIRECT', $result['code']);
        $this->assertCount(1, $history);
        $history = [];
        $result = $this->checker([new Response(302, ['Location' => '/second'])], $history, true)->check($site, 'https://publisher.example.test/');
        $this->assertSame('FETCH_UNAVAILABLE_OR_UNSAFE', $result['code']);
        $this->assertCount(1, $history);
    }

    public function test_blocked_http_and_large_or_non_html_responses_do_not_claim_a_working_installation(): void
    {
        [, $site] = $this->site();
        foreach ([
            [new Response(403), 'HTTP_NOT_SUCCESSFUL'],
            [new Response(200, ['Content-Type' => 'text/plain'], 'not HTML'), 'NOT_HTML'],
            [new Response(200, ['Content-Type' => 'text/html', 'Content-Length' => '9999999']), 'PAGE_TOO_LARGE'],
        ] as [$response, $expected]) {
            $history = [];
            $this->assertSame($expected, $this->checker([$response], $history)->check($site, 'https://publisher.example.test/')['code']);
        }
    }

    public function test_other_organizations_cannot_request_a_check_for_this_site(): void
    {
        [, $site] = $this->site();
        $other = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $this->actingAs($other)->post(route('publisher.sites.installation-check', $site), ['page_url' => 'https://publisher.example.test/'])->assertNotFound();
    }
}
