<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\SiteStatus;
use App\Enums\UserStatus;
use App\Models\Publisher;
use App\Models\SiteNote;
use App\Models\User;
use App\Services\ControlPlane\ActionCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class ControlPlaneFoundationTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_administrator_navigation_only_renders_authorized_destinations(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::FinanceAdmin);

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Publisher accounts')
            ->assertSee('Reporting sources')
            ->assertSee('Finance Operations')
            ->assertSee('Ads.txt')
            ->assertDontSee('Production operations')
            ->assertDontSee('Access control')
            ->assertSee('data-nav-toggle', false);
    }

    public function test_publisher_navigation_is_role_aware_and_has_no_future_dead_links(): void
    {
        $this->seedIdentity();
        $organization = $this->makeOrganization(OrganizationType::Publisher);
        $viewer = $this->makeUser($organization, RoleName::PublisherViewer);
        $this->makePublisherFor($viewer);

        $this->actingAs($viewer)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Publisher overview')
            ->assertSee('Websites')
            ->assertSee('Earnings &amp; Payments', false)
            ->assertSee('Contracts')
            ->assertDontSee('Invite a team member')
            ->assertSee('Supply Chain Compliance')
            ->assertDontSee('Production operations');
    }

    public function test_dashboard_permission_is_required_even_for_an_active_user(): void
    {
        $this->seedIdentity();
        $organization = $this->makeOrganization(OrganizationType::Publisher);
        Publisher::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'legal_name' => 'Roleless Publisher LLC',
            'display_name' => 'Roleless Publisher',
            'status' => 'ACTIVE',
        ]);
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'status' => UserStatus::Active,
            'activated_at' => now(),
        ]);

        $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
    }

    public function test_admin_can_use_publisher_360_and_site_360_while_publishers_cannot_use_internal_routes(): void
    {
        $this->seedIdentity();
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser);
        $site = $this->makeSiteFor($publisher, $publisherUser);
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $session = ['two_factor_passed_at' => now()->timestamp];

        $this->actingAs($admin)->withSession($session)->get(route('admin.publishers.show', $publisher))
            ->assertOk()->assertSee('Publisher 360')->assertSee($site->display_name)->assertSee('Compliance');
        $this->get(route('admin.sites.show', $site))
            ->assertOk()->assertSee('Site 360')->assertSee('One permanent loader')->assertSee('Native demand')->assertSee('Configuration');

        $this->actingAs($publisherUser)->get(route('admin.publishers.show', $publisher))->assertForbidden();
        $this->get(route('admin.sites.show', $site))->assertForbidden();
    }

    public function test_publisher_isolation_and_internal_information_are_preserved_in_the_new_surfaces(): void
    {
        $this->seedIdentity();
        $first = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $firstPublisher = $this->makePublisherFor($first, ['internal_notes' => 'PRIVATE HORUS PUBLISHER NOTE']);
        $firstSite = $this->makeSiteFor($firstPublisher, $first);
        SiteNote::create([
            'organization_id' => $first->organization_id,
            'site_id' => $firstSite->id,
            'author_id' => $first->id,
            'note' => 'PRIVATE HORUS SITE NOTE',
        ]);

        $second = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $secondSite = $this->makeSiteFor($this->makePublisherFor($second), $second);

        $this->actingAs($first)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('PRIVATE HORUS PUBLISHER NOTE')
            ->assertDontSee('PRIVATE HORUS SITE NOTE')
            ->assertDontSee('Horus margin')
            ->assertDontSee('Gross revenue');
        $this->get(route('publisher.sites.show', $secondSite))->assertNotFound();
    }

    public function test_action_center_uses_real_conditions_and_bounded_aggregate_queries(): void
    {
        $this->seedIdentity();
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser, ['status' => 'PENDING', 'onboarding_submitted_at' => now()]);
        $site = $this->makeSiteFor($publisher, $publisherUser);
        $site->update(['status' => SiteStatus::PendingReview]);
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $items = app(ActionCenter::class)->items($admin);

        $this->assertSame(1, collect($items)->firstWhere('key', 'publisher-reviews')['count']);
        $this->assertSame(1, collect($items)->firstWhere('key', 'site-reviews')['count']);
        $this->assertLessThanOrEqual(15, $queries, 'Action Center must remain aggregate-only and avoid N+1 queries.');
    }

    public function test_control_plane_templates_include_mobile_and_accessibility_primitives(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])->get(route('dashboard'))
            ->assertOk()
            ->assertSee('aria-controls="control-navigation"', false)
            ->assertSee('aria-expanded="false"', false)
            ->assertSee('aria-label="Control plane navigation"', false)
            ->assertSee('Action Center');
    }
}
