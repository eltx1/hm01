<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\SiteStatus;
use App\Enums\TrafficGateSitePolicy;
use App\Enums\TrafficGateSiteState;
use App\Models\AuditLog;
use App\Models\StaticDeliveryItem;
use App\Services\Settings\GlobalSettingsService;
use App\Services\TrafficGate\TrafficGateAdminOverviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class TrafficQualityControlCenterTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private const PASSWORD = 'Task51-Operations-Password!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        config([
            'traffic_gate.origin' => 'https://verify.horusmedia.net',
            'traffic_gate.enabled' => false,
            'traffic_gate.site_key' => null,
            'traffic_gate.policy' => 'BALANCED',
            'traffic_gate.initial_wait_ms' => 1500,
            'traffic_gate.max_wait_ms' => 6000,
            'traffic_gate.retry_interval_ms' => 1500,
            'traffic_gate.activity_recovery_enabled' => true,
            'static-delivery.normal_batch_interval_minutes' => 30,
        ]);
    }

    public function test_unauthorized_publisher_and_non_privileged_admin_are_denied_but_authorized_admin_is_allowed(): void
    {
        [$admin, $publisherUser] = $this->identityFixture();
        $finance = $this->makeUser($admin->organization, RoleName::FinanceAdmin, ['email_verified_at' => now()]);

        $this->get(route('admin.operations.traffic-quality'))->assertRedirect(route('admin.login'));
        $this->actingAs($publisherUser)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.operations.traffic-quality'))->assertForbidden();
        $this->actingAs($finance)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.operations.traffic-quality'))->assertForbidden();
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.operations.traffic-quality'))->assertOk()->assertSee('CLIENT TRAFFIC GATE');
    }

    public function test_global_enable_requires_ready_configuration_confirmation_and_publishes_normally(): void
    {
        [$admin, , $site] = $this->identityFixture(active: true);
        app(GlobalSettingsService::class)->set($admin, 'traffic_gate.site_key', '0x4AAAAA_task51_ready_key', 'Prepare Task 51 readiness.');

        $this->asAdmin($admin)->post(route('admin.operations.traffic-quality.master'), [
            'enabled' => 1,
            'reason' => 'Enable client traffic gate for Task 51.',
            'current_password' => self::PASSWORD,
            'impact_confirmation' => 'ENABLE CLIENT TRAFFIC GATE',
        ])->assertRedirect();

        $this->assertTrue((bool) app(GlobalSettingsService::class)->get('traffic_gate.enabled'));
        $this->assertTrue(AuditLog::query()->where('event', 'traffic_gate.enabled')->exists());
        $this->assertTrue(StaticDeliveryItem::withoutGlobalScopes()->where('site_id', $site->id)->where('priority', 'NORMAL')->exists());
    }

    public function test_global_enable_is_blocked_when_sitekey_is_missing(): void
    {
        [$admin] = $this->identityFixture();
        $this->asAdmin($admin)->post(route('admin.operations.traffic-quality.master'), [
            'enabled' => 1,
            'reason' => 'Attempt enable without production Sitekey.',
            'current_password' => self::PASSWORD,
            'impact_confirmation' => 'ENABLE CLIENT TRAFFIC GATE',
        ])->assertSessionHasErrors('enabled');

        $this->assertFalse((bool) app(GlobalSettingsService::class)->get('traffic_gate.enabled'));
        $this->assertSame('SITEKEY_MISSING', app(TrafficGateAdminOverviewService::class)->readiness()->value);
    }

    public function test_emergency_disable_requires_precise_permission_and_queues_urgent_delivery(): void
    {
        [$admin, , $site] = $this->identityFixture(active: true, role: RoleName::OperationsAdmin);
        $this->configureReady($admin);

        $this->asAdmin($admin)->post(route('admin.operations.traffic-quality.emergency-disable'), [
            'reason' => 'Turnstile incident requires immediate Traffic Gate bypass.',
            'current_password' => self::PASSWORD,
            'impact_confirmation' => 'EMERGENCY DISABLE TRAFFIC GATE',
        ])->assertRedirect();

        $this->assertTrue(AuditLog::query()->where('event', 'traffic_gate.emergency_disabled')->exists());
        $this->assertTrue(StaticDeliveryItem::withoutGlobalScopes()->where('site_id', $site->id)->where('priority', 'URGENT')->exists());
    }

    public function test_strict_policy_requires_strong_confirmation_and_audits_change(): void
    {
        [$admin] = $this->identityFixture();
        $this->asAdmin($admin)->post(route('admin.operations.traffic-quality.policy'), [
            'policy' => 'STRICT',
            'reason' => 'Use strict posture during controlled rollout.',
            'current_password' => self::PASSWORD,
            'impact_confirmation' => 'CHANGE TRAFFIC GATE POLICY',
        ])->assertSessionHasErrors('impact_confirmation');

        $this->asAdmin($admin)->post(route('admin.operations.traffic-quality.policy'), [
            'policy' => 'STRICT',
            'reason' => 'Use strict posture during controlled rollout.',
            'current_password' => self::PASSWORD,
            'impact_confirmation' => 'SET STRICT TRAFFIC GATE',
        ])->assertRedirect();

        $this->assertSame('STRICT', app(GlobalSettingsService::class)->get('traffic_gate.policy'));
        $this->assertTrue(AuditLog::query()->where('event', 'traffic_gate.policy_changed')->exists());
    }

    public function test_advanced_timing_validation_enforces_bounds_and_cross_field_relationship(): void
    {
        [$admin] = $this->identityFixture();
        $this->asAdmin($admin)->post(route('admin.operations.traffic-quality.advanced'), [
            'initial_wait_ms' => 5000,
            'max_wait_ms' => 2000,
            'retry_interval_ms' => 1500,
            'activity_recovery_enabled' => 1,
            'reason' => 'Invalid timing relationship should be rejected.',
            'current_password' => self::PASSWORD,
            'impact_confirmation' => 'UPDATE TRAFFIC GATE TIMINGS',
        ])->assertSessionHasErrors('max_wait_ms');

        $this->asAdmin($admin)->post(route('admin.operations.traffic-quality.advanced'), [
            'initial_wait_ms' => 1200,
            'max_wait_ms' => 5000,
            'retry_interval_ms' => 900,
            'activity_recovery_enabled' => 1,
            'reason' => 'Apply bounded Task 51 timing controls.',
            'current_password' => self::PASSWORD,
            'impact_confirmation' => 'UPDATE TRAFFIC GATE TIMINGS',
        ])->assertRedirect();

        $this->assertSame(1200, app(GlobalSettingsService::class)->get('traffic_gate.initial_wait_ms'));
        $this->assertTrue(AuditLog::query()->where('event', 'traffic_gate.timings_changed')->exists());
    }

    public function test_candidate_sitekey_requires_client_pass_before_activation_and_audits_test_and_activation(): void
    {
        [$admin] = $this->identityFixture();
        $candidate = '0x4AAAAA_task51_candidate_public_key';

        $this->asAdmin($admin)->post(route('admin.operations.traffic-quality.sitekey.candidate'), [
            'candidate_sitekey' => $candidate,
        ])->assertRedirect();

        $this->asAdmin($admin)->post(route('admin.operations.traffic-quality.sitekey.activate'), [
            'reason' => 'Activation should remain blocked before client pass.',
            'current_password' => self::PASSWORD,
            'impact_confirmation' => 'ACTIVATE TRAFFIC GATE SITEKEY',
        ])->assertSessionHasErrors('candidate_sitekey');

        $this->asAdmin($admin)->postJson(route('admin.operations.traffic-quality.sitekey.test-result'), [
            'result' => 'CLIENT PASS',
        ])->assertOk()->assertJson(['result' => 'CLIENT PASS']);

        $this->asAdmin($admin)->post(route('admin.operations.traffic-quality.sitekey.activate'), [
            'reason' => 'Activate tested Task 51 public Sitekey.',
            'current_password' => self::PASSWORD,
            'impact_confirmation' => 'ACTIVATE TRAFFIC GATE SITEKEY',
        ])->assertRedirect();

        $this->assertSame($candidate, app(GlobalSettingsService::class)->get('traffic_gate.site_key'));
        $this->assertTrue(AuditLog::query()->where('event', 'traffic_gate.sitekey_candidate_tested')->exists());
        $this->assertTrue(AuditLog::query()->where('event', 'traffic_gate.sitekey_activated')->exists());
    }

    public function test_client_error_and_timeout_results_are_recorded_and_block_activation(): void
    {
        [$admin] = $this->identityFixture();

        foreach (['CLIENT ERROR', 'CLIENT TIMEOUT', 'GATE UNREACHABLE'] as $result) {
            $this->asAdmin($admin)->post(route('admin.operations.traffic-quality.sitekey.candidate'), [
                'candidate_sitekey' => '0x4AAAAA_task51_'.str_replace(' ', '_', strtolower($result)),
            ])->assertRedirect();
            $this->asAdmin($admin)->postJson(route('admin.operations.traffic-quality.sitekey.test-result'), ['result' => $result])
                ->assertOk()->assertJson(['result' => $result]);
            $this->asAdmin($admin)->post(route('admin.operations.traffic-quality.sitekey.activate'), [
                'reason' => 'Failed candidate must not become production Sitekey.',
                'current_password' => self::PASSWORD,
                'impact_confirmation' => 'ACTIVATE TRAFFIC GATE SITEKEY',
            ])->assertSessionHasErrors('candidate_sitekey');
        }
    }

    public function test_page_renders_truthful_language_admin_test_contract_no_secret_field_and_mobile_table(): void
    {
        [$admin] = $this->identityFixture();
        $response = $this->asAdmin($admin)->get(route('admin.operations.traffic-quality'));
        $response->assertOk()
            ->assertSee('CLIENT-ONLY SOFT GATE')
            ->assertSee('This client gate is a soft browser traffic filter. Horus does not perform server-side Turnstile token validation for ad serving.')
            ->assertSee('data-mobile-responsive-table', false)
            ->assertSee('Task 49 Admin-test protocol')
            ->assertDontSee('TOKEN VALIDATED')
            ->assertDontSee('Humans Verified')
            ->assertDontSee('Invalid Traffic Blocked')
            ->assertDontSee('Bot Percentage')
            ->assertDontSee('Fraud Prevented')
            ->assertDontSee('name="secret"', false)
            ->assertDontSee('name="secret_key"', false);
    }

    public function test_site_inherit_enabled_disabled_policy_override_and_bulk_reset(): void
    {
        [$admin, , $site] = $this->identityFixture(active: true);

        foreach ([
            [TrafficGateSiteState::Inherit, TrafficGateSitePolicy::Inherit],
            [TrafficGateSiteState::Enabled, TrafficGateSitePolicy::Strict],
            [TrafficGateSiteState::Disabled, TrafficGateSitePolicy::Permissive],
        ] as [$state, $policy]) {
            $this->asAdmin($admin)->post(route('admin.sites.traffic-gate', $site), [
                'traffic_gate_state' => $state->value,
                'traffic_gate_policy' => $policy->value,
                'reason' => 'Task 51 Site override transition.',
            ])->assertRedirect();
            $site->refresh()->load('servingSettings');
            $this->assertSame($state, $site->servingSettings->traffic_gate_state);
            $this->assertSame($policy, $site->servingSettings->traffic_gate_policy);
        }

        $this->asAdmin($admin)->post(route('admin.operations.traffic-quality.sites.bulk-inherit'), [
            'site_ids' => [$site->id],
            'reason' => 'Reset selected Site after incident test.',
            'current_password' => self::PASSWORD,
            'impact_confirmation' => 'RESET SELECTED SITES TO INHERIT',
        ])->assertRedirect();
        $site->refresh()->load('servingSettings');
        $this->assertSame(TrafficGateSiteState::Inherit, $site->servingSettings->traffic_gate_state);
        $this->assertSame(TrafficGateSitePolicy::Inherit, $site->servingSettings->traffic_gate_policy);
        $this->assertTrue(AuditLog::query()->where('event', 'traffic_gate.site_override_changed')->exists());
    }

    public function test_impact_counts_are_based_on_active_inheriting_sites(): void
    {
        [$admin, $publisherUser, $site] = $this->identityFixture(active: true);
        $publisher = $site->publisher;
        $second = $this->makeSiteFor($publisher, $publisherUser);
        $second->update(['status' => SiteStatus::Active]);
        $second->servingSettings()->update(['traffic_gate_state' => TrafficGateSiteState::Disabled]);
        $third = $this->makeSiteFor($publisher, $publisherUser);
        $third->update(['status' => SiteStatus::Active]);

        $impact = app(TrafficGateAdminOverviewService::class)->impactCounts();
        $this->assertSame(3, $impact['active_sites']);
        $this->assertSame(2, $impact['global_enable']);
    }

    public function test_current_operations_center_remains_available(): void
    {
        [$admin] = $this->identityFixture(role: RoleName::OperationsAdmin);
        $this->asAdmin($admin)->get(route('admin.operations.index'))->assertOk();
    }

    /** @return array{0:\App\Models\User,1:\App\Models\User,2:\App\Models\Site} */
    private function identityFixture(bool $active = false, RoleName $role = RoleName::SuperAdmin): array
    {
        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $admin = $this->makeUser($horus, $role, [
            'password' => Hash::make(self::PASSWORD),
            'email_verified_at' => now(),
        ]);
        $publisherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Task 51 Publisher');
        $publisherUser = $this->makeUser($publisherOrganization, RoleName::PublisherAdmin, ['email_verified_at' => now()]);
        $publisher = $this->makePublisherFor($publisherUser);
        $site = $this->makeSiteFor($publisher, $publisherUser);
        if ($active) {
            $site->update(['status' => SiteStatus::Active]);
        }

        return [$admin, $publisherUser, $site->refresh()];
    }

    private function asAdmin(\App\Models\User $admin): self
    {
        return $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
    }

    private function configureReady(\App\Models\User $admin): void
    {
        $settings = app(GlobalSettingsService::class);
        $settings->set($admin, 'traffic_gate.site_key', '0x4AAAAA_task51_emergency_key', 'Configure Task 51 emergency test Sitekey.');
        $settings->set($admin, 'traffic_gate.enabled', true, 'Configure Task 51 emergency test gate.');
    }
}
