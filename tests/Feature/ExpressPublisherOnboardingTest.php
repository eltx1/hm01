<?php

namespace Tests\Feature;

use App\Enums\ContractStatus;
use App\Enums\OrganizationType;
use App\Enums\PublisherApplicationStatus;
use App\Enums\RoleName;
use App\Enums\SiteStatus;
use App\Enums\SupplyChainReviewStatus;
use App\Models\PlatformAdsTxtRecord;
use App\Models\PublisherApplication;
use App\Models\PublisherContract;
use App\Models\RevenueRule;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\SiteAdsTxtInstallationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class ExpressPublisherOnboardingTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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
    }

    public function test_registration_immediately_activates_publisher_and_open_ended_default_terms(): void
    {
        $this->get(route('publisher-registration.create'))
            ->assertOk()
            ->assertSee('active immediately')
            ->assertSee('default 70% commercial terms')
            ->assertSee('Use at least 10 characters')
            ->assertDontSee('Use at least 14 characters')
            ->assertDontSee('Primary website or domain')
            ->assertDontSee('Traffic');

        $this->post(route('publisher-registration.store'), [
            'name' => 'Fast Publisher',
            'email' => 'fast@publisher.example',
            'publisher_name' => 'Fast Publishing LLC',
            'password' => 'simplepass1',
            'password_confirmation' => 'simplepass1',
            // A stale/legacy client must not be able to create the old pre-approval
            // website-claim flow by posting a domain that the express form ignores.
            'primary_domain' => 'legacy-claim.example',
            '_company_website' => '',
            'legal' => ['PUBLISHER_TERMS' => 1],
            'marketing_opt_in' => 0,
        ])->assertRedirect(route('dashboard'));

        $application = PublisherApplication::withoutGlobalScopes()->firstOrFail();
        $publisher = $application->publisher()->withoutGlobalScopes()->firstOrFail();
        $user = $application->applicant()->firstOrFail();
        $this->assertNull($application->primary_domain);
        $this->assertNull($application->domainClaim()->first());
        $this->assertSame(PublisherApplicationStatus::Approved, $application->status);
        $this->assertSame('ACTIVE', $publisher->status->value);
        $this->assertSame('ACTIVE', $publisher->organization->status->value);
        $this->assertTrue($user->hasRole(RoleName::PublisherAdmin->value));
        $this->assertDatabaseCount('sites', 0);
        $this->assertDatabaseCount('publisher_application_domain_claims', 0);
        $this->assertDatabaseHas('publisher_application_legal_acceptances', [
            'publisher_application_id' => $application->id,
            'document_type' => 'PUBLISHER_TERMS',
            'document_version' => '2026-08',
        ]);

        $contract = PublisherContract::withoutGlobalScopes()->where('publisher_id', $publisher->id)->firstOrFail();
        $this->assertSame(ContractStatus::Active, $contract->status);
        $this->assertSame('70.00', $contract->revenue_share_percent);
        $this->assertNull($contract->ends_at);
        $rule = RevenueRule::withoutGlobalScopes()->where('scope_id', $publisher->id)->firstOrFail();
        $this->assertTrue($rule->is_active);
        $this->assertSame(7000, $rule->currentVersion->publisher_share_bp);

        $this->get(route('dashboard'))->assertOk()->assertSee('Add website');
    }

    public function test_registration_without_current_terms_acceptance_creates_nothing(): void
    {
        $this->post(route('publisher-registration.store'), [
            'name' => 'No Terms',
            'email' => 'no-terms@publisher.example',
            'publisher_name' => 'No Terms Publishing',
            'password' => 'simplepass1',
            'password_confirmation' => 'simplepass1',
            '_company_website' => '',
            'marketing_opt_in' => 0,
        ])->assertSessionHasErrors('legal.PUBLISHER_TERMS');

        $this->assertDatabaseCount('publishers', 0);
        $this->assertDatabaseCount('publisher_contracts', 0);
        $this->assertDatabaseCount('revenue_rules', 0);
    }

    public function test_admin_can_edit_the_active_default_percentage_without_a_new_activation_step(): void
    {
        $this->test_registration_immediately_activates_publisher_and_open_ended_default_terms();
        $application = PublisherApplication::withoutGlobalScopes()->firstOrFail();
        $publisher = $application->publisher()->withoutGlobalScopes()->firstOrFail();
        $contract = PublisherContract::withoutGlobalScopes()->where('publisher_id', $publisher->id)->firstOrFail();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media'), RoleName::SuperAdmin);

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->put(route('admin.publishers.contracts.update', [$publisher, $contract]), [
                'contract_reference' => $contract->contract_reference,
                'starts_at' => $contract->starts_at->toDateString(),
                'ends_at' => '',
                'auto_renews' => 0,
                'revenue_share_percent' => 75,
                'payment_threshold' => 100,
                'currency' => 'USD',
                'payment_terms' => 'NET_30',
                'internal_notes' => 'Updated commercial percentage.',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(ContractStatus::Active, $contract->fresh()->status);
        $this->assertSame('75.00', $contract->fresh()->revenue_share_percent);
        $this->assertNull($contract->fresh()->ends_at);
        $rule = RevenueRule::withoutGlobalScopes()->where('scope_id', $publisher->id)->firstOrFail();
        $this->assertSame(7500, $rule->fresh()->currentVersion->publisher_share_bp);
    }

    public function test_ads_txt_verification_submits_and_admin_approval_activates_automatically(): void
    {
        $this->test_registration_immediately_activates_publisher_and_open_ended_default_terms();
        $application = PublisherApplication::withoutGlobalScopes()->firstOrFail();
        $this->assertDatabaseCount('seller_declarations', 0);

        PlatformAdsTxtRecord::create([
            'advertising_system_domain' => 'master.example',
            'publisher_account_id' => 'master-123',
            'relationship' => 'RESELLER',
            'raw_record' => 'master.example, master-123, RESELLER',
            'record_hash' => hash('sha256', 'master.example|master-123|RESELLER|'),
            'status' => 'ACTIVE',
            'review_status' => SupplyChainReviewStatus::Verified,
        ]);

        $publisherUser = User::query()->where('email', 'fast@publisher.example')->firstOrFail();
        $this->actingAs($publisherUser)->get(route('publisher.sites.create'))
            ->assertOk()
            ->assertSee('Display name')
            ->assertSee('Primary domain')
            ->assertSee('Content category')
            ->assertSee('Primary country')
            ->assertDontSee('Estimated monthly pageviews');

        $this->post(route('publisher.sites.store'), [
            'display_name' => 'Fast News',
            'primary_domain' => 'fast-news.example',
            'content_category' => 'NEWS',
            'country' => 'US',
            // Stale clients cannot reintroduce traffic collection into website creation.
            'estimated_monthly_pageviews' => 987654,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $site = Site::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(0, $site->estimated_monthly_pageviews);
        $this->assertSame('fast-news.example', $application->publisher()->withoutGlobalScopes()->firstOrFail()->business_domain);
        $bundle = app(SiteAdsTxtInstallationService::class)->bundle($site);
        $this->assertTrue($bundle['available']);
        $this->assertCount(2, $bundle['core_records']);
        $this->assertSame([
            'OWNERDOMAIN=fast-news.example',
            'MANAGERDOMAIN=horusmedia.net',
            'CONTACT=mohamed@horusmedia.net',
        ], array_slice($bundle['records'], 0, 3));
        $this->assertContains('master.example, master-123, RESELLER', $bundle['records']);

        $this->get(route('publisher.sites.show', $site))
            ->assertOk()
            ->assertSee('Copy the complete ads.txt block')
            ->assertSee('MANAGERDOMAIN=horusmedia.net')
            ->assertSee('CONTACT=mohamed@horusmedia.net')
            ->assertSee('master.example, master-123, RESELLER');

        Http::fake(['*' => Http::response(implode("\n", $bundle['core_records'])."\n", 200, ['Content-Type' => 'text/plain'])]);
        $domain = $site->domains()->where('is_primary', true)->firstOrFail();
        $this->post(route('publisher.sites.domains.verify', [$site, $domain]), ['method' => 'ADS_TXT'])
            ->assertSessionHasNoErrors();
        $this->assertSame('VERIFIED', $domain->fresh()->verification_status);
        $this->assertSame(SiteStatus::PendingReview, $site->fresh()->status);
        $this->assertDatabaseCount('site_reviews', 1);

        $this->post(route('publisher.sites.domains.verify', [$site, $domain]), ['method' => 'ADS_TXT'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('site_reviews', 1);

        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media'), RoleName::SuperAdmin);
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.sites.approve', $site), ['publisher_message' => 'Approved', 'internal_reason' => 'Content reviewed'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(SiteStatus::Active, $site->fresh()->status);
        $this->assertDatabaseHas('site_reviews', ['site_id' => $site->id, 'decision' => 'APPROVED']);
    }
}
