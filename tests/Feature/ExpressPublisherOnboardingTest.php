<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\PublisherApplicationStatus;
use App\Enums\RoleName;
use App\Enums\SupplyChainReviewStatus;
use App\Models\PlatformAdsTxtRecord;
use App\Models\PublisherApplication;
use App\Models\Site;
use App\Models\User;
use App\Services\PublisherApplications\PublisherApplicationService;
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

    public function test_express_application_has_no_website_or_traffic_gate(): void
    {
        $this->get(route('publisher-registration.create'))
            ->assertOk()
            ->assertSee('under two minutes')
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
        ])->assertRedirect(route('publisher-application.show'));

        $application = PublisherApplication::withoutGlobalScopes()->firstOrFail();
        $this->assertNull($application->primary_domain);
        $this->assertNull($application->domainClaim()->first());
        $this->assertDatabaseCount('sites', 0);

        $this->get(route('publisher-application.show'))
            ->assertOk()
            ->assertSee('No website, ads.txt, traffic data, or technical setup is required here.')
            ->assertDontSee('Monthly pageviews')
            ->assertDontSee('estimated monthly pageviews')
            ->assertDontSee('Organic traffic');

        $this->post(route('publisher-application.complete'), [
            'contact_name' => 'Fast Publisher',
            'legal_name' => 'Fast Publishing LLC',
            'publisher_name' => 'Fast Publishing',
            'content_categories' => ['NEWS'],
            'content_description' => 'Independent news and explanatory reporting for a general audience.',
            'legal' => ['PUBLISHER_TERMS' => 1],
            'marketing_opt_in' => 0,
            'confirm' => 1,
        ])->assertRedirect(route('publisher-application.show'))->assertSessionHasNoErrors();

        $this->assertSame(PublisherApplicationStatus::Submitted, $application->fresh()->status);
        $this->assertDatabaseCount('sites', 0);
        $this->assertDatabaseCount('publisher_application_domain_claims', 0);
    }

    public function test_approved_publisher_adds_a_short_site_and_gets_one_complete_ads_txt_block(): void
    {
        $this->test_express_application_has_no_website_or_traffic_gate();
        $application = PublisherApplication::withoutGlobalScopes()->firstOrFail();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media'), RoleName::SuperAdmin);
        $applications = app(PublisherApplicationService::class);
        $applications->startReview($application, $admin);
        $applications->approve($application->fresh(), $admin);
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
        $this->actingAs($publisherUser)->post(route('publisher.sites.store'), [
            'display_name' => 'Fast News',
            'primary_domain' => 'fast-news.example',
            'content_category' => 'NEWS',
            'country' => 'US',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $site = Site::withoutGlobalScopes()->firstOrFail();
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
    }
}
