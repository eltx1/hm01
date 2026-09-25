<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class WhatsAppContactTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    public function test_publisher_admins_and_viewers_can_contact_horus_from_shared_workspace_pages(): void
    {
        $this->seedIdentity();
        $organization = $this->makeOrganization(OrganizationType::Publisher);
        Publisher::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'legal_name' => 'Publisher LLC',
            'display_name' => 'Publisher',
            'status' => AccountStatus::Active,
        ]);

        foreach ([RoleName::PublisherAdmin, RoleName::PublisherViewer] as $role) {
            $user = $this->makeUser($organization, $role);
            foreach (['dashboard', 'account.index'] as $route) {
                $this->actingAs($user)->get(route($route))->assertOk()
                    ->assertSee('href="https://wa.me/18058318277"', false)
                    ->assertSee('Chat with Horus Media on WhatsApp (opens in a new tab)')
                    ->assertSee('rel="noopener noreferrer"', false)
                    ->assertSee('class="hm-whatsapp-enabled"', false);
            }
        }
    }

    public function test_contact_button_is_not_added_to_other_workspaces_or_login(): void
    {
        $this->seedIdentity();
        $this->get(route('login'))->assertOk()->assertDontSee('https://wa.me/18058318277', false);

        foreach ([
            [OrganizationType::HorusMedia, RoleName::SuperAdmin],
            [OrganizationType::Advertiser, RoleName::AdvertiserAdmin],
            [OrganizationType::Partner, RoleName::PartnerAdmin],
        ] as [$type, $role]) {
            $user = $this->makeUser($this->makeOrganization($type), $role);
            $this->actingAs($user)->withSession(['two_factor_passed_at' => now()->timestamp])
                ->get(route('account.index'))->assertOk()
                ->assertDontSee('https://wa.me/18058318277', false)
                ->assertDontSee('class="hm-whatsapp-enabled"', false);
        }
    }
}
