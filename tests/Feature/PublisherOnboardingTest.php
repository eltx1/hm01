<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class PublisherOnboardingTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_all_seven_onboarding_steps_persist_and_submit_without_optional_gam_gate(): void
    {
        $this->seedIdentity();
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($user, ['status' => 'PENDING']);
        $this->actingAs($user);

        $this->put(route('publisher.onboarding.update', 1), ['legal_name' => 'Publisher LLC', 'display_name' => 'Publisher', 'billing_email' => 'billing@publisher.test', 'contact_name' => 'Owner', 'contact_email' => 'owner@publisher.test'])->assertRedirect(route('publisher.onboarding.show', 2));
        $this->put(route('publisher.onboarding.update', 2), ['beneficiary_name' => 'Publisher LLC', 'payment_method' => 'BANK_TRANSFER', 'currency' => 'USD', 'country' => 'US', 'account_reference' => 'SECRET-ACCOUNT-1234', 'routing_reference' => 'SECRET-SWIFT'])->assertRedirect(route('publisher.onboarding.show', 3));
        $this->put(route('publisher.onboarding.update', 2), ['beneficiary_name' => 'Publisher LLC', 'payment_method' => 'BANK_TRANSFER', 'currency' => 'USD', 'country' => 'US'])->assertRedirect(route('publisher.onboarding.show', 3));
        $this->put(route('publisher.onboarding.update', 3), ['contract_reference' => 'HM-ONBOARD-1', 'revenue_share_percent' => 70, 'payment_threshold' => 100, 'currency' => 'USD', 'payment_terms' => 'NET_30'])->assertRedirect(route('publisher.onboarding.show', 4));
        $this->put(route('publisher.onboarding.update', 4), ['display_name' => 'Publisher News', 'primary_domain' => 'publisher-news.example', 'language' => 'en', 'content_category' => 'News', 'country' => 'US', 'main_traffic_countries' => 'US,GB', 'estimated_monthly_pageviews' => 100000, 'estimated_monthly_users' => 50000, 'current_monetization_providers' => 'AdSense', 'default_revenue_share_percent' => 5, 'prebid_enabled' => 1, 'native_demand_enabled' => 0])->assertRedirect(route('publisher.onboarding.show', 5));
        $this->put(route('publisher.onboarding.update', 5), [])->assertRedirect(route('publisher.onboarding.show', 6));
        $this->put(route('publisher.onboarding.update', 6), ['placement_formats' => 'Leaderboard,In-article', 'placement_notes' => 'Initial plan'])->assertRedirect(route('publisher.onboarding.show', 7));
        $this->put(route('publisher.onboarding.update', 7), ['confirm' => 1])->assertRedirect(route('publisher.onboarding.show', 7));

        $publisher->refresh();
        $site = $publisher->sites()->firstOrFail();
        $this->assertNotNull($publisher->onboarding_submitted_at);
        $this->assertSame(ServingMode::HorusGam, $site->serving_mode);
        $this->assertSame(SiteStatus::PendingReview, $site->status);
        $this->assertSame('70.00', $site->default_revenue_share_percent);
        $this->assertSame(['Leaderboard', 'In-article'], $site->servingSettings->placement_plan['formats']);
        $this->assertDatabaseHas('publisher_payment_profiles', ['publisher_id' => $publisher->id, 'account_last_four' => '1234']);
        $this->assertSame('SECRET-ACCOUNT-1234', $publisher->paymentProfile->payment_details['account_reference']);
        $rawPayment = (string) $this->getRawPaymentDetails($publisher->id);
        $this->assertStringNotContainsString('SECRET-ACCOUNT-1234', $rawPayment);
        $this->assertDatabaseHas('site_reviews', ['site_id' => $site->id, 'decision' => 'PENDING']);
    }

    private function getRawPaymentDetails(string $publisherId): mixed
    {
        return \DB::table('publisher_payment_profiles')->where('publisher_id', $publisherId)->value('payment_details');
    }
}
