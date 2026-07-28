<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\ServingModeChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class SiteManagementTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_new_website_defaults_to_horus_gam_in_site_and_serving_settings(): void
    {
        $this->seedIdentity();
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($user);
        $site = $this->makeSiteFor($publisher, $user);

        $this->assertSame(ServingMode::HorusGam, $site->serving_mode);
        $this->assertSame(ServingMode::HorusGam, $site->servingSettings->serving_mode);
        $this->assertDatabaseHas('site_domains', ['site_id' => $site->id, 'domain' => $site->primary_domain, 'is_primary' => true]);
        $this->assertStringContainsString($site->public_key, $site->installationCode());
        $this->assertStringNotContainsString('HORUS_GAM', $site->installationCode());
    }

    public function test_publisher_cannot_access_another_publishers_website_and_viewer_cannot_create(): void
    {
        $this->seedIdentity();
        $first = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $other = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($other), $other);

        $this->actingAs($first)->get(route('publisher.sites.show', $site))->assertNotFound();

        $viewer = $this->makeUser($first->organization, RoleName::PublisherViewer);
        $this->actingAs($viewer)->post(route('publisher.sites.store'), [])->assertForbidden();
    }

    public function test_publisher_cannot_override_revenue_share_and_configuration_changes_are_versioned(): void
    {
        $this->seedIdentity();
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($user), $user);

        $this->actingAs($user)->put(route('publisher.sites.update', $site), [
            'display_name' => $site->display_name,
            'primary_domain' => $site->primary_domain,
            'language' => $site->language,
            'content_category' => $site->content_category,
            'country' => $site->country,
            'main_traffic_countries' => 'US,GB',
            'estimated_monthly_pageviews' => 120000,
            'estimated_monthly_users' => 60000,
            'current_monetization_providers' => 'AdSense',
            'default_revenue_share_percent' => 5,
            'prebid_enabled' => 1,
            'native_demand_enabled' => 0,
        ])->assertRedirect();

        $site->refresh();
        $this->assertSame('70.00', $site->default_revenue_share_percent);
        $this->assertSame('70.00', $site->servingSettings->revenue_share_percent);
        $this->assertTrue($site->servingSettings->prebid_enabled);
        $this->assertSame(2, $site->servingSettings->configuration_version);
    }

    public function test_complete_website_status_workflow_is_historic_and_audited(): void
    {
        $this->seedIdentity();
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($publisherUser), $publisherUser);
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);

        $this->actingAs($publisherUser)->post(route('publisher.sites.submit', $site))->assertRedirect();
        $this->assertSame(SiteStatus::PendingReview, $site->fresh()->status);

        $session = ['two_factor_passed_at' => now()->timestamp];
        $this->actingAs($admin)->withSession($session)->post(route('admin.sites.approve', $site), ['publisher_message' => 'Approved', 'internal_reason' => 'Content reviewed'])->assertRedirect();
        $this->post(route('admin.sites.activate', $site), ['reason' => 'Ready'])->assertRedirect();
        $this->post(route('admin.sites.suspend', $site), ['reason' => 'Operational check'])->assertRedirect();
        $this->post(route('admin.sites.reactivate', $site), ['reason' => 'Check complete'])->assertRedirect();

        $this->assertSame(SiteStatus::Active, $site->fresh()->status);
        $this->assertDatabaseCount('site_status_history', 6);
        $this->assertDatabaseHas('audit_logs', ['event' => 'site.status.changed', 'auditable_id' => $site->id]);
    }

    public function test_serving_mode_can_change_back_to_horus_gam_without_changing_installation_code(): void
    {
        $this->seedIdentity();
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($publisherUser), $publisherUser);
        $code = $site->installationCode();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.sites.serving-mode', $site), ['serving_mode' => 'PUBLISHER_GAM', 'reason' => 'Publisher requested test'])->assertRedirect();
        $firstChange = ServingModeChange::firstOrFail();
        $this->post(route('admin.sites.serving-mode', $site), ['serving_mode' => 'HORUS_GAM', 'reason' => 'Return to platform default', 'rollback_reference_id' => $firstChange->id])->assertRedirect();

        $site->refresh();
        $this->assertSame(ServingMode::HorusGam, $site->serving_mode);
        $this->assertSame($code, $site->installationCode());
        $this->assertDatabaseCount('serving_mode_changes', 2);
        $this->assertDatabaseHas('serving_mode_changes', ['site_id' => $site->id, 'new_mode' => 'HORUS_GAM', 'rollback_reference_id' => $firstChange->id]);
    }

    public function test_revenue_share_and_emergency_pause_are_synchronized_and_audited(): void
    {
        $this->seedIdentity();
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($publisherUser), $publisherUser);
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.sites.revenue-share', $site), ['revenue_share_percent' => 75.5, 'reason' => 'Signed amendment'])->assertRedirect();
        $this->post(route('admin.sites.emergency-pause', $site), ['reason' => 'Publisher request'])->assertRedirect();

        $site->refresh();
        $this->assertSame('75.50', $site->default_revenue_share_percent);
        $this->assertSame('75.50', $site->servingSettings->revenue_share_percent);
        $this->assertSame(ServingMode::Paused, $site->serving_mode);
        $this->assertSame(SiteStatus::Suspended, $site->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'site.revenue_share.changed', 'auditable_id' => $site->id]);
    }

    public function test_rejection_internal_data_is_hidden_from_publisher_and_site_can_be_archived(): void
    {
        $this->seedIdentity();
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($publisherUser), $publisherUser);
        $this->actingAs($publisherUser)->post(route('publisher.sites.submit', $site));
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.sites.reject', $site), ['publisher_message' => 'Please clarify ownership.', 'internal_reason' => 'PRIVATE REVIEW REASON'])->assertRedirect();
        $this->post(route('admin.sites.notes.store', $site), ['note' => 'PRIVATE SITE NOTE'])->assertRedirect();
        $this->post(route('admin.sites.archive', $site), ['reason' => 'Publisher withdrew the site'])->assertRedirect();

        $this->assertSame(SiteStatus::Archived, $site->fresh()->status);
        $this->actingAs($publisherUser)->get(route('publisher.sites.show', $site))
            ->assertOk()->assertSee('Please clarify ownership.')->assertDontSee('PRIVATE REVIEW REASON')->assertDontSee('PRIVATE SITE NOTE');
    }
}
