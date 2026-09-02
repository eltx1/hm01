<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class AdminWebsitePublisherLinkTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_website_operations_links_the_publisher_name_to_publisher_360(): void
    {
        $this->seedIdentity();

        $publisherUser = $this->makeUser(
            $this->makeOrganization(OrganizationType::Publisher),
            RoleName::PublisherAdmin,
        );
        $publisher = $this->makePublisherFor($publisherUser, [
            'display_name' => 'Acme Publisher',
        ]);
        $this->makeSiteFor($publisher, $publisherUser, [
            'display_name' => 'Acme News',
            'primary_domain' => 'news.example.com',
        ]);

        $admin = $this->makeUser(
            $this->makeOrganization(OrganizationType::HorusMedia),
            RoleName::SuperAdmin,
        );

        $this->actingAs($admin)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.sites.index'))
            ->assertOk()
            ->assertSee('Acme News')
            ->assertSee('href="'.route('admin.publishers.show', $publisher).'"', false)
            ->assertSee('aria-label="Open Publisher 360 for Acme Publisher"', false)
            ->assertSee('>Acme Publisher</a>', false);
    }
}
