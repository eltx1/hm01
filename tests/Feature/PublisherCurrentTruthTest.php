<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\PublisherApplication;
use App\Services\ControlPlane\ActionCenter;
use App\Services\ControlPlane\ControlPlaneNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class PublisherCurrentTruthTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_publisher_navigation_has_no_legacy_onboarding_destination(): void
    {
        $this->seedIdentity();
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher, 'Publisher'), RoleName::PublisherAdmin);
        $this->makePublisherFor($user);

        $labels = collect(app(ControlPlaneNavigation::class)->for($user))
            ->flatMap(fn (array $group) => collect($group['items'])->pluck('label'));

        $this->assertFalse($labels->contains('Onboarding'));
        $this->assertTrue($labels->contains('My Websites'));
        $this->assertTrue($labels->contains('Monetization Health'));
        $this->assertTrue($labels->contains('Reports & Earnings'));
        $this->assertTrue($labels->contains('Statements'));
        $this->assertTrue($labels->contains('Payouts'));

        $groups = collect(app(ControlPlaneNavigation::class)->for($user))->pluck('label')->all();
        $this->assertSame(['Home', 'Websites', 'Reports & Money', 'Account & Help'], $groups);
    }

    public function test_publisher_websites_page_uses_task_oriented_cards_and_direct_destinations(): void
    {
        $this->seedIdentity();
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher, 'Publisher'), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($user);
        $site = $this->makeSiteFor($publisher, $user, [
            'display_name' => 'Publisher News',
            'primary_domain' => 'publisher-news.example',
        ]);

        $this->actingAs($user)->get(route('publisher.sites.index'))
            ->assertOk()
            ->assertSee('Manage each website from one place.')
            ->assertSee('Publisher News')
            ->assertSee('publisher-news.example')
            ->assertSee('Open website')
            ->assertSee('Monetization health')
            ->assertSee('Reports & earnings')
            ->assertSee(route('publisher.sites.show', $site), false);
    }

    public function test_publisher_dashboard_remains_safe_for_role_without_finance_permission(): void
    {
        $this->seedIdentity();
        $organization = $this->makeOrganization(OrganizationType::Publisher, 'Publisher');
        $admin = $this->makeUser($organization, RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($admin);
        $this->makeSiteFor($publisher, $admin);
        $viewer = $this->makeUser($organization, RoleName::PublisherViewer);
        $financePermission = Permission::query()->where('name', 'finance.publisher.view_own')->firstOrFail();
        $viewer->roles->first()->permissions()->detach($financePermission->id);
        $viewer->unsetRelation('roles');

        $this->actingAs($viewer)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Reporting currency · USD')
            ->assertSee('Manage websites')
            ->assertDontSee('View reports &amp; earnings', false);
    }

    public function test_new_publisher_is_not_prompted_for_payment_details_before_a_payout_is_relevant(): void
    {
        $this->seedIdentity();
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher, 'Publisher'), RoleName::PublisherAdmin);
        $this->makePublisherFor($user);

        $items = collect(app(ActionCenter::class)->items($user));

        $this->assertNull($items->firstWhere('key', 'publisher-payment-profile'));
    }

    public function test_legacy_onboarding_urls_redirect_without_mutating_publisher_state(): void
    {
        $this->seedIdentity();
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher, 'Publisher'), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($user, ['onboarding_step' => 4]);

        $this->actingAs($user)
            ->get(route('publisher.onboarding.show', 4))
            ->assertRedirect(route('publisher.sites.index'));

        $this->put(route('publisher.onboarding.update', 4), [
            'display_name' => 'Should Not Be Applied',
            'primary_domain' => 'stale.example',
        ])->assertRedirect(route('publisher.sites.index'));

        $publisher->refresh();
        $this->assertSame(4, (int) $publisher->onboarding_step);
        $this->assertNotSame('Should Not Be Applied', $publisher->display_name);
        $this->assertDatabaseCount('sites', 0);
    }

    public function test_express_registration_accepts_a_simple_ten_character_password(): void
    {
        $this->seedIdentity();
        Notification::fake();
        Config::set('security.authentication.email_verification_required', false);
        Config::set('publisher-applications.public_registration_enabled', true);
        Config::set('publisher-applications.turnstile.enabled', false);
        Config::set('publisher-applications.legal_documents', [
            'PUBLISHER_TERMS' => [
                'label' => 'Publisher Terms',
                'version' => '2026-08',
                'url' => 'https://horusmedia.net/legal/publisher-terms?v=2026-08',
                'required' => true,
            ],
        ]);

        $this->post(route('publisher-registration.store'), [
            'name' => 'Simple Publisher',
            'email' => 'simple@publisher.example',
            'publisher_name' => 'Simple Publishing LLC',
            'password' => 'simplepass1',
            'password_confirmation' => 'simplepass1',
            '_company_website' => '',
            'legal' => ['PUBLISHER_TERMS' => 1],
            'marketing_opt_in' => 0,
        ])->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'simple@publisher.example']);
        $this->assertDatabaseCount('publisher_applications', 1);
        $this->assertNull(PublisherApplication::withoutGlobalScopes()->firstOrFail()->primary_domain);
    }
}
