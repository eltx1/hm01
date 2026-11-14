<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\SiteStatus;
use App\Models\AuditLog;
use App\Services\Inventory\SiteConfigPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class RecaptchaObservationTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private const KEY = '6LcObservationTest_123456789';
    private const SECRET = 'private-observation-test-value';

    private function fixture(): array
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin, ['email_verified_at' => now()]);
        $publisher = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin, ['email_verified_at' => now()]);
        $site = $this->makeSiteFor($this->makePublisherFor($publisher), $publisher);
        return [$admin, $publisher, $site];
    }

    public function test_default_is_disabled_and_secret_is_encrypted_never_public_or_audited(): void
    {
        [$admin, , $site] = $this->fixture();
        $this->assertFalse($site->servingSettings->recaptcha_observation_enabled);
        $site->update(['status' => SiteStatus::Active]);
        $this->actingAs($admin)->withHeader('X-Request-ID', '11111111-1111-4111-8111-111111111111')->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.sites.recaptcha-observation', $site), ['enabled' => 1, 'site_key' => self::KEY, 'recaptcha_observation_secret' => self::SECRET])
            ->assertSessionHasNoErrors()->assertSessionHas('operation_reference', '11111111-1111-4111-8111-111111111111')->assertRedirect();
        $settings = $site->refresh()->servingSettings;
        $this->assertTrue($settings->recaptcha_observation_enabled);
        $this->assertSame(self::SECRET, $settings->recaptcha_observation_secret);
        $this->assertStringNotContainsString(self::SECRET, $settings->getRawOriginal('recaptcha_observation_secret'));
        $this->assertArrayNotHasKey('recaptcha_observation_secret', $settings->toArray());
        $payload = app(SiteConfigPublisher::class)->preview($site, ConfigEnvironment::Production);
        $this->assertSame(['enabled' => true, 'siteKey' => self::KEY], $payload['recaptchaObservation']);
        $this->assertStringNotContainsString(self::SECRET, json_encode($payload));
        $this->assertStringNotContainsString(self::SECRET, AuditLog::query()->get()->toJson());
        $this->assertDatabaseHas('config_versions', ['site_id' => $site->id, 'status' => 'PENDING_DELIVERY']);
        $versions = \App\Models\ConfigVersion::where('site_id', $site->id)->count();
        $this->post(route('admin.sites.recaptcha-observation', $site), ['enabled' => 1, 'site_key' => self::KEY, 'recaptcha_observation_secret' => self::SECRET])->assertSessionHasNoErrors();
        $this->assertSame($versions, \App\Models\ConfigVersion::where('site_id', $site->id)->count());
        $this->get(route('admin.sites.show', $site))->assertOk()->assertSee('Google reCAPTCHA v3')->assertSee('publication not confirmed')->assertDontSee(self::SECRET);

    }

    public function test_secret_is_retained_on_blank_and_can_be_removed_without_changing_cloudflare(): void
    {
        [$admin, , $site] = $this->fixture();
        $site->servingSettings->update(['recaptcha_observation_secret' => self::SECRET]);
        $before = [$site->servingSettings->traffic_gate_state, $site->servingSettings->traffic_gate_policy];
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        $this->post(route('admin.sites.recaptcha-observation', $site), ['enabled' => 1, 'site_key' => self::KEY, 'recaptcha_observation_secret' => ''])->assertSessionHasNoErrors();
        $this->assertSame(self::SECRET, $site->refresh()->servingSettings->recaptcha_observation_secret);
        $this->post(route('admin.sites.recaptcha-observation', $site), ['enabled' => 0, 'site_key' => self::KEY, 'clear_secret' => 1])->assertSessionHasNoErrors();
        $this->assertNull($site->refresh()->servingSettings->recaptcha_observation_secret);
        $this->assertSame($before, [$site->servingSettings->traffic_gate_state, $site->servingSettings->traffic_gate_policy]);
        $payload = app(SiteConfigPublisher::class)->preview($site, ConfigEnvironment::Production);
        $this->assertSame(['enabled' => false, 'siteKey' => null], $payload['recaptchaObservation']);
    }

    public function test_publisher_cannot_enable_observation_and_invalid_input_does_not_flash_secret(): void
    {
        [$admin, $publisher, $site] = $this->fixture();
        $url = route('admin.sites.recaptcha-observation', $site);
        $this->actingAs($publisher)->post($url, ['enabled' => 1, 'site_key' => self::KEY])->assertForbidden();
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])->post($url,
            ['enabled' => 1, 'site_key' => '<script>', 'recaptcha_observation_secret' => self::SECRET])->assertSessionHasErrors('site_key');
        $this->assertNull(session()->getOldInput('recaptcha_observation_secret'));
        $this->assertFalse($site->refresh()->servingSettings->recaptcha_observation_enabled);
    }
}
