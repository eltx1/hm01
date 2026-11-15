<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\ConfigVersion;
use App\Services\Diagnostics\PerformancePolicy;
use App\Services\Inventory\SiteConfigPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class PerformanceObservationSettingsTest extends TestCase
{
    use RefreshDatabase, InteractsWithIdentity, InteractsWithPublisherSites;

    public function test_default_and_invalid_infrastructure_are_inert_and_cannot_publish_arbitrary_endpoints(): void
    {
        $policy = app(PerformancePolicy::class);
        config(['diagnostics.collector_url' => null]);
        $this->assertSame(['enabled' => false], $policy->publicConfig(['performance' => ['enabled' => true, 'sampleBp' => 100]]));
        config(['diagnostics.collector_url' => 'https://evil.test']);
        $this->assertSame(['enabled' => false], $policy->publicConfig(['performance' => ['enabled' => true]]));
        config(['diagnostics.collector_url' => 'https://metrics.horusmedia.net']);
        $result = $policy->publicConfig(['performance' => ['enabled' => true, 'sampleBp' => 100, 'collectorOrigin' => 'PRIVATE', 'token' => 'PRIVATE']]);
        $this->assertTrue($result['enabled']);
        $this->assertSame('https://metrics.horusmedia.net', $result['collectorOrigin']);
        $this->assertMatchesRegularExpression('~^https://cdn\.horusmedia\.net/runtime/performance/hm-performance\.[a-f0-9]{16}\.js$~D', $result['scriptUrl']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($result));
        foreach ([0,1001,'100',null] as $rate) {
            if ($rate === null) continue;
            $this->assertSame(['enabled' => false], $policy->publicConfig(['performance' => ['enabled' => true, 'sampleBp' => $rate]]));
        }
    }

    public function test_admin_opt_in_is_audited_idempotent_and_does_not_modify_protection_or_other_settings(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $owner = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($owner), $owner);
        $site->update(['status' => 'ACTIVE']);
        $site->siteConfig->update(['observability_settings' => ['localDiagnostics' => true]]);
        $before = $site->servingSettings->toArray();
        config(['diagnostics.collector_url' => 'https://metrics.horusmedia.net']);
        $url = route('admin.sites.performance.update', $site);
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        $this->put($url, ['enabled' => 1, 'sample_bp' => 100])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($before, $site->refresh()->servingSettings->toArray());
        $this->assertTrue($site->siteConfig->observability_settings['localDiagnostics']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'site.performance_observation.updated', 'auditable_id' => $site->id]);
        $count = ConfigVersion::where('site_id', $site->id)->count();
        $this->put($url, ['enabled' => 1, 'sample_bp' => 100])->assertSessionHasNoErrors();
        $this->assertSame($count, ConfigVersion::where('site_id', $site->id)->count());
        $this->assertSame(1, AuditLog::where('event', 'site.performance_observation.updated')->count());
        $payload = app(SiteConfigPublisher::class)->preview($site->refresh(), ConfigEnvironment::Production);
        $this->assertTrue($payload['observability']['performance']['enabled']);
        $this->assertSame(100, $payload['observability']['performance']['sampleBp']);
        $this->put($url, ['enabled' => 0, 'sample_bp' => 100])->assertSessionHasNoErrors();
        $payload = app(SiteConfigPublisher::class)->preview($site->refresh(), ConfigEnvironment::Production);
        $this->assertSame(['enabled' => false], $payload['observability']['performance']);
        $site->siteConfig->update(['observability_settings' => ['performance' => ['enabled' => false, 'token' => 'PRIVATE_FIELD', 'scriptUrl' => 'https://evil.test']]]);
        $payload = app(SiteConfigPublisher::class)->preview($site->refresh(), ConfigEnvironment::Production);
        $this->assertSame(['enabled' => false], $payload['observability']['performance']);
        $this->assertStringNotContainsString('PRIVATE_FIELD', json_encode($payload));
    }

    public function test_access_validation_and_missing_setup_do_not_change_settings_or_make_api_calls(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $owner = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($owner), $owner);
        Http::preventStrayRequests();
        config(['diagnostics.collector_url' => null, 'diagnostics.account_id' => null, 'static-delivery.cloudflare.account_id' => null]);
        $url = route('admin.sites.performance.update', $site);
        $this->actingAs($owner)->put($url, ['enabled' => 1, 'sample_bp' => 100])->assertForbidden();
        $this->get(route('admin.sites.performance.show', $site))->assertForbidden();
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        $this->put($url, ['enabled' => 1, 'sample_bp' => 100])->assertSessionHasErrors('enabled');
        $this->put($url, ['enabled' => 1, 'sample_bp' => 1001])->assertSessionHasErrors('sample_bp');
        $before = $site->refresh()->siteConfig->getRawOriginal('observability_settings');
        $this->get(route('admin.sites.performance.show', $site))->assertOk()->assertSee('SETUP_REQUIRED')->assertSee('Missing observations do not mean zero latency');
        $this->assertSame($before, $site->refresh()->siteConfig->getRawOriginal('observability_settings'));
        $site->siteConfig->update(['observability_settings' => ['performance' => ['enabled' => 'PRIVATE', 'sampleBp' => ['PRIVATE']]]]);
        $this->get(route('admin.sites.performance.show', $site))->assertOk()->assertDontSee('PRIVATE');
        Http::assertNothingSent();
    }
}
