<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class AdminPublisherSiteCreationTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_horus_admin_adds_a_website_through_the_publishers_normal_lifecycle(): void
    {
        $this->seedIdentity();
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser, ['display_name' => 'Acme Publisher']);
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::OperationsAdmin);
        $session = ['two_factor_passed_at' => now()->timestamp];

        $this->actingAs($admin)->withSession($session)
            ->get(route('admin.publishers.show', $publisher))
            ->assertOk()
            ->assertSee('Add website')
            ->assertSee(route('admin.publishers.sites.create', $publisher), false);

        $this->actingAs($admin)->withSession($session)
            ->get(route('admin.publishers.sites.create', $publisher))
            ->assertOk()
            ->assertSee('Add website to Acme Publisher')
            ->assertSee(route('admin.publishers.sites.store', $publisher), false);

        $response = $this->actingAs($admin)->withSession($session)
            ->post(route('admin.publishers.sites.store', $publisher), [
                'display_name' => 'Acme News',
                'primary_domain' => 'https://News.Example.COM/editorial/path',
                'content_category' => 'NEWS',
                'country' => 'eg',
                'status' => SiteStatus::Active->value,
                'default_revenue_share_percent' => 5,
                'prebid_enabled' => 1,
            ]);

        $site = Site::withoutGlobalScopes()->where('publisher_id', $publisher->id)->firstOrFail();
        $response->assertRedirect(route('admin.sites.show', $site));

        $this->assertSame($publisher->organization_id, $site->organization_id);
        $this->assertSame('news.example.com', $site->primary_domain);
        $this->assertSame('EG', $site->country);
        $this->assertSame(['EG'], $site->main_traffic_countries);
        $this->assertSame(SiteStatus::Draft, $site->status);
        $this->assertSame(ServingMode::HorusGam, $site->serving_mode);
        $this->assertSame('70.00', $site->default_revenue_share_percent);
        $this->assertFalse($site->prebid_enabled);
        $this->assertDatabaseHas('site_domains', [
            'site_id' => $site->id,
            'domain' => 'news.example.com',
            'is_primary' => true,
        ]);
        $this->assertNotEmpty($site->domains()->firstOrFail()->verification_token);
        $this->assertDatabaseHas('site_serving_settings', [
            'site_id' => $site->id,
            'serving_mode' => ServingMode::HorusGam->value,
            'revenue_share_percent' => 70,
        ]);
        $this->assertDatabaseHas('site_configs', ['site_id' => $site->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('site_status_history', [
            'site_id' => $site->id,
            'new_status' => SiteStatus::Draft->value,
            'changed_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'site.created',
            'auditable_id' => $site->id,
            'actor_id' => $admin->id,
        ]);

        $this->actingAs($publisherUser)
            ->get(route('publisher.sites.show', $site))
            ->assertOk()
            ->assertSee('Acme News')
            ->assertSee('news.example.com');
    }

    public function test_horus_user_without_site_management_permission_cannot_add_a_publisher_website(): void
    {
        $this->seedIdentity();
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser);
        $supportAgent = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SupportAgent);

        $this->actingAs($supportAgent)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.publishers.sites.store', $publisher), [
                'display_name' => 'Forbidden Site',
                'primary_domain' => 'forbidden.example.com',
                'content_category' => 'NEWS',
                'country' => 'US',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('sites', ['publisher_id' => $publisher->id]);
    }
}
