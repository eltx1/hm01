<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\SiteStatus;
use App\Models\StaticDeliveryItem;
use App\Services\Settings\GlobalSettingsService;
use App\Services\TrafficGate\TrafficGateAdminOverviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class TrafficQualityRuntimeSafetyTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private const PASSWORD = 'Task51-Runtime-Safety-Password!';

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
            'security.headers.content_security_policy' => "default-src 'self'; frame-ancestors 'none'; script-src 'self' 'unsafe-inline'; connect-src 'self'",
        ]);
    }

    public function test_traffic_quality_csp_allows_only_the_gate_page_frame_and_keeps_admin_test_runtime_non_inline(): void
    {
        [$admin] = $this->fixture();

        $trafficQuality = $this->asAdmin($admin)->get(route('admin.operations.traffic-quality'));
        $trafficQuality->assertOk()->assertSee('id="traffic-gate-client-test"', false);
        $trafficCsp = (string) $trafficQuality->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('frame-src https://verify.horusmedia.net', $trafficCsp);
        $this->assertStringContainsString("script-src 'self'", $trafficCsp);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $trafficCsp);

        $operations = $this->asAdmin($admin)->get(route('admin.operations.index'));
        $operations->assertOk();
        $operationsCsp = (string) $operations->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('https://verify.horusmedia.net', $operationsCsp);
    }

    public function test_emergency_disable_is_reported_as_pending_urgent_in_the_global_static_summary(): void
    {
        [$admin, $site] = $this->fixture(active: true, role: RoleName::OperationsAdmin);
        $settings = app(GlobalSettingsService::class);
        $settings->set($admin, 'traffic_gate.site_key', '0x4AAAAA_task51_runtime_safety', 'Prepare Traffic Gate runtime safety test.');
        $settings->set($admin, 'traffic_gate.enabled', true, 'Enable gate before runtime safety incident test.');

        $this->asAdmin($admin)->post(route('admin.operations.traffic-quality.emergency-disable'), [
            'reason' => 'Runtime safety incident requires immediate gate bypass.',
            'current_password' => self::PASSWORD,
            'impact_confirmation' => 'EMERGENCY DISABLE TRAFFIC GATE',
        ])->assertRedirect();

        $this->assertTrue(StaticDeliveryItem::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('priority', 'URGENT')
            ->exists());
        $this->assertSame('PENDING URGENT', app(TrafficGateAdminOverviewService::class)->staticSummary()['state']);
    }

    /** @return array{0:\App\Models\User,1:\App\Models\Site} */
    private function fixture(bool $active = false, RoleName $role = RoleName::SuperAdmin): array
    {
        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $admin = $this->makeUser($horus, $role, [
            'password' => Hash::make(self::PASSWORD),
            'email_verified_at' => now(),
        ]);
        $publisherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Task 51 Runtime Publisher');
        $publisherUser = $this->makeUser($publisherOrganization, RoleName::PublisherAdmin, ['email_verified_at' => now()]);
        $publisher = $this->makePublisherFor($publisherUser);
        $site = $this->makeSiteFor($publisher, $publisherUser);
        if ($active) {
            $site->update(['status' => SiteStatus::Active]);
        }

        return [$admin, $site->refresh()];
    }

    private function asAdmin(\App\Models\User $admin): self
    {
        return $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
    }
}
