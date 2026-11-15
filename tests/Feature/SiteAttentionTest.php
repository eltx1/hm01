<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\MonetizationHealthState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class SiteAttentionTest extends TestCase
{
    use RefreshDatabase, InteractsWithIdentity, InteractsWithPublisherSites;

    public function test_queue_uses_stored_evidence_without_private_details_or_writes_and_rejects_publishers(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $owner = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($owner);
        foreach (['Finding' => 'BROKEN', 'Stale' => 'HEALTHY', 'Missing' => null] as $name => $status) {
            $site = $this->makeSiteFor($publisher, $owner, ['display_name' => 'Queue'.$name]);
            $site->update(['status' => 'ACTIVE']);
            if ($status) MonetizationHealthState::create(['organization_id' => $site->organization_id, 'site_id' => $site->id,
                'state_key' => 'renderer_conflict', 'status' => $status, 'details' => ['token' => 'PRIVATE_TOKEN'],
                'observed_at' => $name === 'Stale' ? now()->subHours(2) : now()]);
        }
        $before = MonetizationHealthState::get()->toJson();
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        foreach (['findings' => 'Finding', 'stale' => 'Stale', 'missing' => 'Missing'] as $filter => $expected) {
            $r = $this->get(route('admin.operations.site-attention', ['evidence' => $filter]))->assertOk()->assertSee('Queue'.$expected)->assertDontSee('PRIVATE_TOKEN');
            foreach (array_diff(['Finding', 'Stale', 'Missing'], [$expected]) as $other) $r->assertDontSee('Queue'.$other);
        }
        $this->assertSame($before, MonetizationHealthState::get()->toJson());
        $this->actingAs($owner)->get(route('admin.operations.site-attention'))->assertForbidden();
    }
}
