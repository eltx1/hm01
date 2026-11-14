<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class PublisherSetupGuidanceTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_guidance_reads_existing_state_without_changing_review_or_activation(): void
    {
        $this->seedIdentity();
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($user), $user);
        $this->actingAs($user);
        foreach (['DRAFT' => 'Verify the website ads.txt', 'PENDING_REVIEW' => 'Website review is pending', 'ACTIVE' => 'Review installation and monetization'] as $status => $title) {
            $site->update(['status' => $status]);
            $this->get(route('publisher.sites.show', $site))->assertOk()->assertSee($title);
            $this->assertSame($status, $site->fresh()->status->value);
        }
    }
}
