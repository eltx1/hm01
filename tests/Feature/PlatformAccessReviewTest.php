<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\ConfigVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class PlatformAccessReviewTest extends TestCase
{
    use RefreshDatabase, InteractsWithIdentity, InteractsWithPublisherSites;

    public function test_operational_views_and_settings_follow_existing_staff_role_boundaries(): void
    {
        $this->seedIdentity();
        $horus = $this->makeOrganization(OrganizationType::HorusMedia);
        $owner = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($owner), $owner);
        $version = ConfigVersion::withoutGlobalScopes()->create([
            'organization_id' => $site->organization_id, 'site_id' => $site->id, 'site_config_id' => $site->siteConfig->id,
            'environment' => 'PRODUCTION', 'version' => 1, 'status' => 'DEPLOYED', 'payload' => [],
            'checksum' => str_repeat('a', 64), 'file_path' => 'review.json', 'created_by' => $owner->id,
        ]);
        foreach ([
            [RoleName::OperationsAdmin, 200, 200, 200],
            [RoleName::AdOpsAdmin, 200, 200, 200],
            [RoleName::SupportAgent, 200, 403, 403],
            [RoleName::FinanceAdmin, 403, 403, 403],
        ] as [$role, $history, $queue, $performance]) {
            $staff = $this->makeUser($horus, $role);
            $this->actingAs($staff)->withSession(['two_factor_passed_at' => now()->timestamp]);
            $this->get(route('admin.sites.config.history', $site))->assertStatus($history);
            $this->get(route('admin.sites.config.demand-review', [$site, $version]))->assertStatus($history);
            $this->get(route('admin.operations.site-attention'))->assertStatus($queue);
            $this->get(route('admin.sites.performance.show', $site))->assertStatus($performance);
            if (in_array($role, [RoleName::SupportAgent, RoleName::FinanceAdmin], true)) {
                $this->put(route('admin.sites.performance.update', $site), ['enabled' => true, 'sample_bp' => 100])->assertForbidden();
            }
        }
        $this->assertSame(1, ConfigVersion::withoutGlobalScopes()->where('site_id', $site->id)->count());
    }

    public function test_referral_page_ignores_foreign_publisher_selection_and_denies_commission_administration(): void
    {
        $this->seedIdentity();
        $owner = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($owner, ['display_name' => 'Own publisher']);
        $other = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $foreign = $this->makePublisherFor($other, ['display_name' => 'PRIVATE_FOREIGN_PUBLISHER']);
        $childUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $this->makePublisherFor($childUser, ['display_name' => 'PRIVATE_FOREIGN_REFERRAL', 'referred_by_publisher_id' => $foreign->id]);
        $viewer = $this->makeUser($publisher->organization, RoleName::PublisherViewer);
        foreach ([$owner, $viewer] as $user) {
            $this->actingAs($user)->get(route('publisher.affiliate.index', ['publisher_id' => $foreign->id]))
                ->assertOk()->assertViewHas('referralsCount', 0)->assertDontSee('PRIVATE_FOREIGN');
            $this->withSession(['two_factor_passed_at' => now()->timestamp])
                ->put(route('admin.publisher-affiliates.settings.update'), ['commission_bp' => 10000])->assertForbidden();
        }
        $partner = $this->makeUser($this->makeOrganization(OrganizationType::Partner), RoleName::PartnerAdmin);
        $this->actingAs($partner)->get(route('publisher.affiliate.index'))->assertForbidden();
    }
}
