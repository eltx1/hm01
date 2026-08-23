<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Services\Sites\SiteAdsTxtInstallationService;
use App\Services\SupplyChain\HorusSellerIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class SiteActivationAdsTxtReadinessTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_latest_failed_matching_recheck_invalidates_previous_horus_core_verification(): void
    {
        $this->seedIdentity();
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser, ['business_domain' => 'publisher-owner.example']);
        $site = $this->makeSiteFor($publisher, $publisherUser);

        app(HorusSellerIdentityService::class)->ensureForSite($site, $publisherUser);
        $service = app(SiteAdsTxtInstallationService::class);
        $bundle = $service->bundle($site->fresh());
        $domain = $site->domains()->where('is_primary', true)->firstOrFail();

        $this->assertTrue($bundle['available']);
        $this->assertCount(2, $bundle['core_records']);

        Http::fake([
            '*' => Http::sequence()
                ->push(implode("\n", $bundle['core_records'])."\n", 200, ['Content-Type' => 'text/plain'])
                ->push($bundle['core_records'][0]."\n", 200, ['Content-Type' => 'text/plain']),
        ]);

        $successful = $service->verify($site->fresh(), $domain, $publisherUser);
        $this->assertSame('VERIFIED', $successful->status);
        $this->assertTrue($service->hasCurrentCoreVerification($site->fresh()));

        $failed = $service->verify($site->fresh(), $domain->fresh(), $publisherUser);
        $this->assertSame('FAILED', $failed->status);
        $this->assertFalse($service->hasCurrentCoreVerification($site->fresh()));
    }
}
