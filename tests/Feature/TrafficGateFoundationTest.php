<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\SiteStatus;
use App\Enums\TrafficGateReadiness;
use App\Enums\TrafficGateSitePolicy;
use App\Enums\TrafficGateSiteState;
use App\Models\AuditLog;
use App\Models\StaticDeliveryItem;
use App\Services\Inventory\SiteConfigPublisher;
use App\Services\Inventory\SiteConfigurationBuilder;
use App\Services\Operations\PlatformControlService;
use App\Services\Settings\GlobalSettingsService;
use App\Services\Settings\TypedSettingsRegistry;
use App\Services\StaticDelivery\PublicPayloadGuard;
use App\Services\StaticDelivery\StaticDeliverySnapshotBuilder;
use App\Services\TrafficGate\TrafficGateConfigurationResolver;
use App\Services\TrafficGate\TrafficGateGlobalSettingsService;
use App\Services\TrafficGate\TrafficGateSiteSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class TrafficGateFoundationTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private const PASSWORD = 'Task48-Operations-Password!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        config([
            'static-delivery.normal_batch_interval_minutes' => 30,
            'traffic_gate.origin' => 'https://verify.horusmedia.net',
            'traffic_gate.enabled' => false,
            'traffic_gate.site_key' => null,
            'traffic_gate.policy' => 'BALANCED',
            'traffic_gate.initial_wait_ms' => 1500,
            'traffic_gate.max_wait_ms' => 6000,
            'traffic_gate.retry_interval_ms' => 1500,
            'traffic_gate.activity_recovery_enabled' => true,
        ]);
    }

    public function test_default_disabled_global_enable_disable_and_missing_sitekey_readiness(): void
    {
        [$site, $admin] = $this->fixture();
        $resolver = app(TrafficGateConfigurationResolver::class);
        $settings = app(GlobalSettingsService::class);

        $default = $resolver->resolve($site);
        $this->assertFalse($default->enabled);
        $this->assertSame(TrafficGateReadiness::Disabled, $default->readiness);
        $this->assertSame('BALANCED', $default->policy->value);

        $settings->set($admin, 'traffic_gate.enabled', true, 'Task 48 missing key readiness test.');
        $missing = $resolver->resolve($site->refresh());
        $this->assertFalse($missing->enabled);
        $this->assertSame(TrafficGateReadiness::ConfigurationRequired, $missing->readiness);

        $settings->set($admin, 'traffic_gate.site_key', '0x4AAAAA_task48_public_key', 'Task 48 public site key test.');
        $ready = $resolver->resolve($site->refresh());
        $this->assertTrue($ready->enabled);
        $this->assertSame(TrafficGateReadiness::Ready, $ready->readiness);

        $settings->set($admin, 'traffic_gate.enabled', false, 'Task 48 global disable test.');
        $disabled = $resolver->resolve($site->refresh());
        $this->assertFalse($disabled->enabled);
        $this->assertSame(TrafficGateReadiness::Disabled, $disabled->readiness);
    }

    public function test_invalid_gate_origin_never_activates_or_reaches_browser_payload(): void
    {
        [$site, $admin] = $this->fixture();
        $this->configureReadyGlobal($admin);
        config(['traffic_gate.origin' => 'https://third-party.example']);

        $resolver = app(TrafficGateConfigurationResolver::class);
        $this->assertFalse($resolver->validOrigin('https://third-party.example'));
        $resolved = $resolver->resolve($site);

        $this->assertFalse($resolved->enabled);
        $this->assertNull($resolved->gateOrigin);
        $this->assertSame(TrafficGateReadiness::InvalidConfiguration, $resolved->readiness);

        $payload = app(SiteConfigPublisher::class)->preview($site->refresh(), ConfigEnvironment::Production);
        $this->assertFalse($payload['trafficGate']['enabled']);
        $this->assertNull($payload['trafficGate']['gateOrigin']);
        $this->assertSame('INVALID_CONFIGURATION', $payload['trafficGate']['readiness']);
        $this->assertStringNotContainsString('third-party.example', json_encode($payload['trafficGate'], JSON_THROW_ON_ERROR));
    }

    public function test_strict_balanced_and_permissive_policy_resolution(): void
    {
        [$site, $admin] = $this->fixture();
        $settings = app(GlobalSettingsService::class);
        $settings->set($admin, 'traffic_gate.site_key', '0x4AAAAA_task48_policy_key', 'Task 48 public policy test key.');
        $settings->set($admin, 'traffic_gate.enabled', true, 'Task 48 policy resolution enable.');

        foreach (['STRICT', 'BALANCED', 'PERMISSIVE'] as $policy) {
            $settings->set($admin, 'traffic_gate.policy', $policy, 'Task 48 policy preset test.');
            $this->assertSame($policy, app(TrafficGateConfigurationResolver::class)->resolve($site->refresh())->policy->value);
        }
    }

    public function test_site_inherit_enabled_disabled_and_policy_override(): void
    {
        [$site, $admin] = $this->fixture();
        $settings = app(GlobalSettingsService::class);
        $settings->set($admin, 'traffic_gate.site_key', '0x4AAAAA_task48_override_key', 'Task 48 override public key.');
        $resolver = app(TrafficGateConfigurationResolver::class);

        $inherit = $resolver->resolve($site->refresh());
        $this->assertFalse($inherit->enabled);
        $this->assertSame(TrafficGateReadiness::Disabled, $inherit->readiness);

        $site->servingSettings()->update([
            'traffic_gate_state' => TrafficGateSiteState::Enabled,
            'traffic_gate_policy' => TrafficGateSitePolicy::Strict,
        ]);
        $enabled = $resolver->resolve($site->refresh());
        $this->assertTrue($enabled->enabled);
        $this->assertSame('STRICT', $enabled->policy->value);
        $this->assertSame(TrafficGateReadiness::Ready, $enabled->readiness);

        $settings->set($admin, 'traffic_gate.enabled', true, 'Task 48 global enable for site disable test.');
        $site->servingSettings()->update(['traffic_gate_state' => TrafficGateSiteState::Disabled]);
        $disabled = $resolver->resolve($site->refresh());
        $this->assertFalse($disabled->enabled);
        $this->assertSame(TrafficGateReadiness::Disabled, $disabled->readiness);

        $site->servingSettings()->update([
            'traffic_gate_state' => TrafficGateSiteState::Inherit,
            'traffic_gate_policy' => TrafficGateSitePolicy::Permissive,
        ]);
        $this->assertSame('PERMISSIVE', $resolver->resolve($site->refresh())->policy->value);
    }

    public function test_timing_bounds_and_cross_field_relationship_are_enforced(): void
    {
        [, $admin] = $this->fixture();
        $registry = app(TypedSettingsRegistry::class);

        foreach ([
            ['traffic_gate.initial_wait_ms', 499],
            ['traffic_gate.max_wait_ms', 15001],
            ['traffic_gate.retry_interval_ms', 0],
        ] as [$key, $value]) {
            try {
                $registry->normalize($key, $value);
                $this->fail("{$key} should reject {$value}.");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        $settings = app(GlobalSettingsService::class);
        $settings->set($admin, 'traffic_gate.initial_wait_ms', 5000, 'Task 48 upper initial wait bound.');

        $this->expectException(ValidationException::class);
        $settings->set($admin, 'traffic_gate.max_wait_ms', 2000, 'Task 48 invalid cross-field wait relationship.');
    }

    public function test_public_static_payload_is_sanitized_and_existing_serving_configuration_is_unchanged(): void
    {
        [$site, $admin] = $this->fixture();
        $this->configureReadyGlobal($admin);

        $baseline = app(SiteConfigurationBuilder::class)->build($site->refresh(), ConfigEnvironment::Production, 1);
        $payload = app(SiteConfigPublisher::class)->preview($site->refresh(), ConfigEnvironment::Production);

        $this->assertTrue($payload['trafficGate']['enabled']);
        $this->assertSame('CLOUDFLARE_TURNSTILE_CLIENT_ONLY', $payload['trafficGate']['provider']);
        $this->assertSame('https://verify.horusmedia.net', $payload['trafficGate']['gateOrigin']);
        $this->assertSame('BALANCED', $payload['trafficGate']['policy']);
        $this->assertSame('READY', $payload['trafficGate']['readiness']);
        $this->assertStringNotContainsString('secret', strtolower(json_encode($payload['trafficGate'], JSON_THROW_ON_ERROR)));
        $this->assertStringNotContainsString('credential', strtolower(json_encode($payload['trafficGate'], JSON_THROW_ON_ERROR)));
        app(PublicPayloadGuard::class)->validate($payload);

        foreach (['engines', 'prebid', 'directDemand', 'gpt', 'controls', 'placements'] as $key) {
            $this->assertSame($baseline[$key], $payload[$key], "Traffic Gate must not change existing {$key} configuration.");
        }

        $this->assertArrayNotHasKey('secret', config('traffic_gate'));
        $this->assertArrayNotHasKey('secret_key', config('traffic_gate'));
    }

    public function test_global_emergency_disable_wins_over_site_enabled_and_is_in_global_static_control(): void
    {
        [$site, $admin] = $this->fixture();
        $this->configureReadyGlobal($admin);
        $site->servingSettings()->update(['traffic_gate_state' => TrafficGateSiteState::Enabled]);

        app(PlatformControlService::class)->set(
            'PLATFORM',
            null,
            'TRAFFIC_GATE',
            true,
            'Task 48 emergency bypass test.',
            $admin,
        );

        $resolved = app(TrafficGateConfigurationResolver::class)->resolve($site->refresh());
        $this->assertFalse($resolved->enabled);
        $this->assertSame(TrafficGateReadiness::Disabled, $resolved->readiness);
        $this->assertTrue(AuditLog::query()->where('event', 'traffic_gate.emergency_disabled')->exists());

        $snapshot = app(StaticDeliverySnapshotBuilder::class)->build();
        $control = json_decode($snapshot->files['configs/_global/control.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($control['controls']['trafficGateDisabled']);
        $this->assertArrayHasKey('adServingDisabled', $control['controls']);
        $this->assertArrayHasKey('gamDisabled', $control['controls']);
        $this->assertArrayHasKey('prebidDisabled', $control['controls']);
        $this->assertArrayHasKey('directJsDisabled', $control['controls']);
    }

    public function test_global_and_site_changes_publish_normally_and_are_audited(): void
    {
        [$site, $admin] = $this->fixture(active: true);

        app(TrafficGateGlobalSettingsService::class)->set(
            $admin,
            'traffic_gate.site_key',
            '0x4AAAAA_task48_publication_key',
            'Task 48 normal global publication.',
        );
        app(TrafficGateGlobalSettingsService::class)->set(
            $admin,
            'traffic_gate.enabled',
            true,
            'Task 48 normal enable publication.',
        );

        $this->assertTrue(StaticDeliveryItem::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('priority', 'NORMAL')
            ->exists());
        $this->assertFalse(StaticDeliveryItem::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('priority', 'URGENT')
            ->exists());
        $this->assertTrue(AuditLog::query()->where('event', 'traffic_gate.site_key_replaced')->exists());
        $this->assertTrue(AuditLog::query()->where('event', 'traffic_gate.enabled')->exists());

        $version = app(TrafficGateSiteSettingsService::class)->update(
            $site->refresh(),
            TrafficGateSiteState::Enabled,
            TrafficGateSitePolicy::Permissive,
            $admin,
            'Task 48 site override publication.',
        );
        $this->assertNotNull($version);
        $this->assertSame('NORMAL', $version->deliveryItem->priority->value);
        $this->assertTrue(AuditLog::query()->where('event', 'traffic_gate.site_override_changed')->exists());
    }

    public function test_platform_emergency_disable_queues_urgent_static_delivery(): void
    {
        [$site, $admin] = $this->fixture(active: true);
        $this->configureReadyGlobal($admin);

        $this->actingAs($admin)->post(route('admin.operations.controls'), [
            'scope_type' => 'PLATFORM',
            'scope_id' => null,
            'control_key' => 'TRAFFIC_GATE',
            'is_disabled' => '1',
            'reason' => 'Task 48 production traffic gate incident.',
            'current_password' => self::PASSWORD,
        ])->assertRedirect();

        $urgent = StaticDeliveryItem::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('priority', 'URGENT')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($urgent);
        $this->assertTrue($urgent->available_at->lessThanOrEqualTo(now()));
        $this->assertTrue(AuditLog::query()->where('event', 'traffic_gate.emergency_disabled')->exists());
    }

    /** @return array{0:\App\Models\Site,1:\App\Models\User} */
    private function fixture(bool $active = false): array
    {
        $horusOrganization = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $admin = $this->makeUser($horusOrganization, RoleName::SuperAdmin, [
            'password' => Hash::make(self::PASSWORD),
            'email_verified_at' => now(),
        ]);
        $publisherUser = $this->makeUser(
            $this->makeOrganization(OrganizationType::Publisher, 'Task 48 Publisher'),
            RoleName::PublisherAdmin,
        );
        $publisher = $this->makePublisherFor($publisherUser);
        $site = $this->makeSiteFor($publisher, $publisherUser);

        if ($active) {
            $site->update(['status' => SiteStatus::Active]);
        }

        return [$site->refresh(), $admin];
    }

    private function configureReadyGlobal(\App\Models\User $admin): void
    {
        $settings = app(GlobalSettingsService::class);
        $settings->set($admin, 'traffic_gate.site_key', '0x4AAAAA_task48_ready_key', 'Task 48 ready public site key.');
        $settings->set($admin, 'traffic_gate.enabled', true, 'Task 48 ready global enable.');
    }
}
