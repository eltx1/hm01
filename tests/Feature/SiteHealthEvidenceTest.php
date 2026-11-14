<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\SiteStatus;
use App\Models\MonetizationHealthState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class SiteHealthEvidenceTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_admin_can_review_existing_records_without_running_or_mutating_the_monitor(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin, ['email_verified_at' => now()]);
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($user), $user);
        $site->update(['status' => SiteStatus::Active]);
        $record = MonetizationHealthState::create(['organization_id' => $site->organization_id, 'site_id' => $site->id,
            'state_key' => 'renderer_conflict', 'status' => 'BROKEN', 'fingerprint' => str_repeat('a', 64),
            'details' => ['private' => 'INTERNAL_VALUE_NEVER_RENDER'], 'observed_at' => now()]);
        $before = $record->getRawOriginal();
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.sites.index'))->assertOk()->assertSee('Stored checks have findings')
            ->assertSee('data-health-evidence="CURRENT_FINDINGS"', false)->assertDontSee('INTERNAL_VALUE_NEVER_RENDER');
        $this->assertSame($before, $record->fresh()->getRawOriginal());
        $this->assertSame(1, MonetizationHealthState::count());
        $record->update(['observed_at' => now()->subHours(2)]);
        $this->get(route('admin.sites.index'))->assertOk()->assertSee('Stored evidence is stale');
        $this->actingAs($user)->get(route('admin.sites.index'))->assertForbidden();
    }
}
