<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
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
        $this->assertTrue($labels->contains('Websites'));
        $this->assertTrue($labels->contains('Monetization Center'));
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
