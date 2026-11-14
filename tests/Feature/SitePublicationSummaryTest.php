<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\SiteStatus;
use App\Models\ConfigVersion;
use App\Services\Inventory\SiteConfigPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class SitePublicationSummaryTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_admin_sees_pending_and_confirmed_versions_without_a_get_publishing_anything(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin, ['email_verified_at' => now()]);
        $publisher = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($publisher), $publisher);
        $site->update(['status' => SiteStatus::Active]);
        $version = app(SiteConfigPublisher::class)->publish($site, ConfigEnvironment::Production, $admin);
        $count = ConfigVersion::count();
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.sites.show', $site))->assertOk()
            ->assertSee('Saved and published configuration')->assertSee('Publication pending')
            ->assertSee('data-publication-state="PENDING"', false);
        $this->assertSame($count, ConfigVersion::count());
        $version->update(['status' => 'DEPLOYED', 'published_at' => now()]);
        $version->deliveryItem->update(['status' => 'DEPLOYED', 'delivered_at' => now()]);
        $site->siteConfig->update(['production_version' => $version->version]);
        $this->get(route('admin.sites.show', $site))->assertOk()->assertSee('Publication confirmed');
        app(SiteConfigPublisher::class)->publish($site->fresh(), ConfigEnvironment::Production, $admin);
        $this->get(route('admin.sites.show', $site))->assertOk()->assertSee('Publication pending');
        $this->assertSame($version->version, (int) $site->siteConfig->fresh()->production_version);
        $this->actingAs($publisher)->get(route('admin.sites.show', $site))->assertForbidden();
    }
}
