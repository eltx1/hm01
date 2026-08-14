<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Models\BidderAccount;
use App\Models\BidderSiteMapping;
use App\Models\GoogleCmpEvidence;
use App\Models\PrebidAdapter;
use App\Models\PrebidBidder;
use App\Models\PrebidBuild;
use App\Models\PrebidSetting;
use App\Models\PrivacyDiagnosticEvidence;
use App\Models\PrivacyDiagnosticToken;
use App\Services\Privacy\PrivacyReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class PrivacyReadinessTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private $admin;

    private $publisherUser;

    private $publisher;

    private $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        config([
            'privacy.diagnostic_endpoint' => 'https://app.horusmedia.net/privacy-diagnostics/report',
            'privacy.diagnostic_ttl_minutes' => 10,
            'privacy.probe_stale_days' => 30,
            'privacy.google_cmp_evidence_stale_days' => 90,
        ]);

        $this->admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $publisherOrganization = $this->makeOrganization(OrganizationType::Publisher);
        $this->publisherUser = $this->makeUser($publisherOrganization, RoleName::PublisherAdmin);
        $this->publisher = $this->makePublisherFor($this->publisherUser);
        $this->site = $this->makeSiteFor($this->publisher, $this->publisherUser, ['primary_domain' => 'privacy.publisher.test']);
        $this->site->siteConfig()->update(['status' => 'ACTIVE', 'privacy_settings' => [
            'mode' => 'STRICT',
            'cmp' => ['timeoutMs' => 1200, 'actionOnTimeout' => 'LIMITED_ADS', 'tcfRequired' => true, 'gppRequired' => false],
            'signals' => ['gpc' => true],
            'requireConsentBeforeAds' => true,
        ]]);
    }

    public function test_diagnostic_token_is_hashed_required_expiring_and_single_use(): void
    {
        $issued = $this->issue();
        $stored = PrivacyDiagnosticToken::query()->findOrFail($issued['record']->id);
        $this->assertNotSame($issued['token'], $stored->getRawOriginal('token_hash'));
        $this->assertSame(hash('sha256', $issued['token']), $stored->getRawOriginal('token_hash'));

        $this->postReport($this->payload(), null)->assertUnauthorized();
        $this->postReport($this->payload(), $issued['token'])->assertAccepted();
        $this->postReport($this->payload(), $issued['token'])->assertUnprocessable();
        $this->assertSame(1, PrivacyDiagnosticEvidence::withoutGlobalScopes()->count());

        $expired = $this->issue();
        $expired['record']->update(['expires_at' => now()->subSecond()]);
        $this->postReport($this->payload(), $expired['token'])->assertUnprocessable();
    }

    public function test_token_is_site_and_hostname_scoped_and_unknown_origins_are_rejected(): void
    {
        $issued = $this->issue();
        $other = $this->makeSiteFor($this->publisher, $this->publisherUser, ['primary_domain' => 'other.publisher.test']);

        $this->postJson('/privacy-diagnostics/report', $this->payload('other.publisher.test'), [
            'Origin' => 'https://other.publisher.test',
            'X-Horus-Diagnostic-Token' => $issued['token'],
        ])->assertUnprocessable();

        $this->postJson('/privacy-diagnostics/report', $this->payload('attacker.invalid'), [
            'Origin' => 'https://attacker.invalid',
            'X-Horus-Diagnostic-Token' => $issued['token'],
        ])->assertForbidden();
        $this->postJson('/privacy-diagnostics/report', $this->payload(), [
            'Origin' => 'https://privacy.publisher.test:4443',
            'X-Horus-Diagnostic-Token' => $issued['token'],
        ])->assertForbidden();
        $this->assertSame(0, PrivacyDiagnosticEvidence::withoutGlobalScopes()->count());
        $this->assertNotNull($other);
    }

    public function test_payload_is_bounded_and_schema_rejects_tc_gpp_and_identifier_material(): void
    {
        $issued = $this->issue();
        $this->call('POST', '/privacy-diagnostics/report', [], [], [], [
            'HTTP_ORIGIN' => 'https://privacy.publisher.test',
            'HTTP_X_HORUS_DIAGNOSTIC_TOKEN' => $issued['token'],
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '5000',
        ], str_repeat('x', 5000))->assertStatus(413);

        foreach ([
            ['path' => 'tcf.tcString', 'value' => 'FULL_TC_STRING_SECRET'],
            ['path' => 'gpp.gppString', 'value' => 'FULL_GPP_STRING_SECRET'],
            ['path' => 'userIdentifier', 'value' => 'visitor-123'],
            ['path' => 'fingerprint', 'value' => 'browser-fingerprint'],
        ] as $forbidden) {
            $attempt = $this->payload();
            data_set($attempt, $forbidden['path'], $forbidden['value']);
            $this->postReport($attempt, $issued['token'])->assertUnprocessable();
        }
        $this->assertSame(0, PrivacyDiagnosticEvidence::withoutGlobalScopes()->count());

        $this->postReport($this->payload(), $issued['token'])->assertAccepted();
        $encoded = json_encode(PrivacyDiagnosticEvidence::withoutGlobalScopes()->firstOrFail()->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('FULL_TC_STRING_SECRET', $encoded);
        $this->assertStringNotContainsString('FULL_GPP_STRING_SECRET', $encoded);
        $this->assertStringNotContainsString('visitor-123', $encoded);
        $this->assertStringNotContainsString('browser-fingerprint', $encoded);
    }

    public function test_tcf_and_gpp_live_findings_distinguish_responding_from_missing(): void
    {
        $ready = $this->issue();
        $this->postReport($this->payload(), $ready['token'])->assertAccepted()->assertJsonPath('status', 'READY');
        $result = app(PrivacyReadinessService::class)->admin($this->site->fresh());
        $this->assertSame('READY', data_get($result, 'sections.tcf.status'));
        $this->assertSame('LIVE_VERIFIED', data_get($result, 'overall.live_state'));

        $missingTcf = $this->issue();
        $payload = $this->payload();
        $payload['tcf'] = ['detected' => false, 'responded' => false, 'cmpId' => null, 'eventStatus' => null];
        $this->postReport($payload, $missingTcf['token'])->assertAccepted()->assertJsonPath('status', 'BLOCKED');
        $blocked = app(PrivacyReadinessService::class)->admin($this->site->fresh());
        $this->assertSame('BLOCKED', data_get($blocked, 'sections.tcf.status'));
        $this->assertContains('TCF_REQUIRED_BUT_API_MISSING', collect($blocked['findings'])->pluck('code')->all());

        $this->site->siteConfig()->update(['privacy_settings' => [
            'mode' => 'STRICT',
            'cmp' => ['timeoutMs' => 1200, 'actionOnTimeout' => 'LIMITED_ADS', 'tcfRequired' => false, 'gppRequired' => true],
            'signals' => ['gpc' => true], 'requireConsentBeforeAds' => true,
        ]]);
        $missingGpp = $this->issue();
        $payload = $this->payload();
        $payload['gpp'] = ['detected' => false, 'responded' => false, 'applicableSections' => []];
        $this->postReport($payload, $missingGpp['token'])->assertAccepted()->assertJsonPath('status', 'BLOCKED');
        $gpp = app(PrivacyReadinessService::class)->admin($this->site->fresh());
        $this->assertSame('BLOCKED', data_get($gpp, 'sections.gpp.status'));
        $this->assertContains('GPP_REQUIRED_BUT_API_MISSING', collect($gpp['findings'])->pluck('code')->all());
    }

    public function test_old_live_evidence_becomes_stale(): void
    {
        $issued = $this->issue();
        $this->postReport($this->payload(), $issued['token'])->assertAccepted();
        PrivacyDiagnosticEvidence::withoutGlobalScopes()->firstOrFail()->update(['observed_at' => now()->subDays(31)]);

        $result = app(PrivacyReadinessService::class)->admin($this->site->fresh());
        $this->assertSame('STALE', data_get($result, 'sections.live.status'));
        $this->assertContains('PRIVACY_PROBE_STALE', collect($result['findings'])->pluck('code')->all());
    }

    public function test_prebid_module_and_configuration_findings_use_the_active_build(): void
    {
        $this->site->update(['serving_mode' => ServingMode::HorusDirect, 'prebid_enabled' => true]);
        $this->site->servingSettings()->update([
            'serving_mode' => ServingMode::HorusDirect,
            'prebid_enabled' => true,
            'prebid_configured_mode' => 'STANDALONE',
        ]);
        $build = PrebidBuild::query()->create([
            'organization_id' => $this->admin->organization_id,
            'name' => 'privacy-test', 'version' => '11.14.0',
            'file_path' => 'assets/prebid/test.js', 'minified_path' => 'assets/prebid/test.min.js',
            'modules' => ['storageControl'], 'is_active' => true, 'built_at' => now(),
        ]);
        $settings = PrebidSetting::withoutGlobalScopes()->create([
            'organization_id' => $this->site->organization_id,
            'scope' => PrebidSetting::SCOPE_SITE_STANDALONE,
            'site_id' => $this->site->id,
            'prebid_build_id' => $build->id,
            'enabled' => true,
            'auction_timeout_ms' => 1200, 'price_granularity' => 'medium', 'currency' => 'USD', 'bidder_sequence' => 'fixed',
            'consent_behavior' => [], 'lazy_loading' => [], 'refresh_behavior' => [], 'gam_fallback' => false,
        ]);

        $blocked = app(PrivacyReadinessService::class)->admin($this->site->fresh());
        $this->assertSame('BLOCKED', data_get($blocked, 'sections.prebid.status'));
        $this->assertContains('PREBID_TCF_MODULE_MISSING', collect($blocked['findings'])->pluck('code')->all());

        $build->update(['modules' => ['consentManagementTcf', 'tcfControl', 'storageControl']]);
        $settings->update(['consent_behavior' => ['gdpr' => ['cmpApi' => 'iab', 'timeout' => 800]]]);
        $ready = app(PrivacyReadinessService::class)->admin($this->site->fresh());
        $this->assertSame('READY', data_get($ready, 'sections.prebid.status'));

        $build->update(['modules' => ['consentManagementTcf', 'tcfControl']]);
        $warning = app(PrivacyReadinessService::class)->admin($this->site->fresh());
        $this->assertSame('WARNING', data_get($warning, 'sections.prebid.status'));
        $this->assertContains('PREBID_STORAGE_CONTROL_MODULE_MISSING', collect($warning['findings'])->pluck('code')->all());
    }

    public function test_google_cmp_evidence_has_not_verified_verified_and_stale_states(): void
    {
        $settings = $this->site->siteConfig->privacy_settings;
        data_set($settings, 'cmp.googleCmpEvidenceRequired', true);
        $this->site->siteConfig()->update(['privacy_settings' => $settings]);
        $service = app(PrivacyReadinessService::class);

        $missing = $service->admin($this->site->fresh());
        $this->assertSame('NOT_VERIFIED', data_get($missing, 'sections.google.details.evidence_status'));
        $service->recordGoogleCmpEvidence($this->site, ConfigEnvironment::Production, $this->cmpData('NOT_VERIFIED', today()), $this->admin);
        $notVerified = $service->admin($this->site->fresh());
        $this->assertSame('NOT_VERIFIED', data_get($notVerified, 'sections.google.details.evidence_status'));
        $this->assertContains('GOOGLE_CMP_EVIDENCE_NOT_VERIFIED', collect($notVerified['findings'])->pluck('code')->all());

        $service->recordGoogleCmpEvidence($this->site, ConfigEnvironment::Production, $this->cmpData('VERIFIED', today()), $this->admin);
        $verified = $service->admin($this->site->fresh());
        $this->assertSame('VERIFIED', data_get($verified, 'sections.google.details.evidence_status'));

        GoogleCmpEvidence::withoutGlobalScopes()->where('site_id', $this->site->id)->update(['last_verification_date' => today()->subDays(91)]);
        $stale = $service->admin($this->site->fresh());
        $this->assertSame('STALE', data_get($stale, 'sections.google.details.evidence_status'));
    }

    public function test_required_provider_privacy_support_is_never_guessed(): void
    {
        $this->site->update(['prebid_enabled' => true]);
        $adapter = PrebidAdapter::query()->create([
            'code' => 'privacy-test', 'display_name' => 'Privacy Test', 'module_code' => 'privacyTestBidAdapter',
            'required_public_parameters' => [], 'supported_media_types' => ['banner'],
            'documentation_url' => 'https://provider.example/privacy', 'enabled' => true,
        ]);
        $bidder = PrebidBidder::query()->create([
            'organization_id' => $this->admin->organization_id, 'prebid_adapter_id' => $adapter->id,
            'code' => 'privacy-test', 'display_name' => 'Privacy Test', 'enabled' => true,
            'privacy_capabilities' => ['tcf' => 'NOT_SUPPORTED', 'gpp' => 'UNKNOWN'],
        ]);
        $account = BidderAccount::withoutGlobalScopes()->create([
            'organization_id' => $this->site->organization_id, 'prebid_bidder_id' => $bidder->id,
            'name' => 'Privacy account', 'enabled' => true, 'created_by' => $this->admin->id,
        ]);
        BidderSiteMapping::withoutGlobalScopes()->create([
            'organization_id' => $this->site->organization_id, 'bidder_account_id' => $account->id,
            'site_id' => $this->site->id, 'enabled' => true,
        ]);

        $blocked = app(PrivacyReadinessService::class)->admin($this->site->fresh());
        $this->assertSame('BLOCKED', data_get($blocked, 'sections.providers.status'));
        $this->assertContains('PROVIDER_TCF_NOT_SUPPORTED', collect($blocked['findings'])->pluck('code')->all());

        $bidder->update(['privacy_capabilities' => ['tcf' => 'UNKNOWN', 'gpp' => 'UNKNOWN']]);
        $unknown = app(PrivacyReadinessService::class)->admin($this->site->fresh());
        $this->assertSame('WARNING', data_get($unknown, 'sections.providers.status'));
        $this->assertContains('PROVIDER_TCF_CAPABILITY_UNKNOWN', collect($unknown['findings'])->pluck('code')->all());
    }

    public function test_publishers_cannot_modify_admin_verification_or_run_cross_tenant_tests(): void
    {
        $this->actingAs($this->publisherUser)->put(route('admin.sites.google-cmp-evidence.update', $this->site), $this->cmpData('VERIFIED', today()) + ['environment' => 'PRODUCTION'])
            ->assertForbidden();
        $this->actingAs($this->publisherUser)->post(route('admin.sites.privacy-diagnostics.run', $this->site), ['environment' => 'PRODUCTION'])
            ->assertForbidden();
        $this->assertSame(0, GoogleCmpEvidence::withoutGlobalScopes()->count());
        $this->assertSame(0, PrivacyDiagnosticToken::query()->count());
    }

    public function test_live_privacy_test_does_not_change_serving_configuration(): void
    {
        $beforeSite = $this->site->only(['serving_mode', 'prebid_enabled', 'native_demand_enabled', 'status']);
        $beforeConfig = $this->site->siteConfig->fresh()->toArray();
        $issued = $this->issue();
        $this->postReport($this->payload(), $issued['token'])->assertAccepted();

        $this->assertSame($beforeSite, $this->site->fresh()->only(array_keys($beforeSite)));
        $this->assertSame($beforeConfig, $this->site->siteConfig->fresh()->toArray());
    }

    private function issue(): array
    {
        return app(PrivacyReadinessService::class)->issueDiagnostic($this->site->fresh(), ConfigEnvironment::Production, $this->admin);
    }

    private function postReport(array $payload, ?string $token)
    {
        $headers = ['Origin' => 'https://privacy.publisher.test'];
        if ($token !== null) {
            $headers['X-Horus-Diagnostic-Token'] = $token;
        }

        return $this->postJson('/privacy-diagnostics/report', $payload, $headers);
    }

    private function payload(string $hostname = 'privacy.publisher.test'): array
    {
        return [
            'loaderVersion' => '2.0.0', 'configVersion' => 7, 'hostname' => $hostname, 'timestamp' => now()->toIso8601String(),
            'tcf' => ['detected' => true, 'responded' => true, 'cmpId' => 42, 'eventStatus' => 'tcloaded'],
            'gpp' => ['detected' => true, 'responded' => true, 'applicableSections' => [7, 8]],
            'gpcDetected' => true, 'configuredTimeoutAction' => 'LIMITED_ADS',
            'prebid' => ['modulesPresent' => ['consentManagementTcf', 'consentManagementGpp', 'storageControl'], 'consentConfigured' => true, 'storageControlConfigured' => true, 'activityControlsConfigured' => true],
            'privacyGateRespected' => true,
        ];
    }

    private function cmpData(string $status, $date): array
    {
        return [
            'cmp_name' => 'Operator verified CMP', 'tcf_cmp_id' => 42, 'platform' => 'WEB',
            'last_verification_date' => $date->toDateString(), 'operator_verification_status' => $status,
        ];
    }
}
