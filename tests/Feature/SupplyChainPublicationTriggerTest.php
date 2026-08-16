<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\BidderAdsTxtRecord;
use App\Models\PrebidBidder;
use App\Models\SellerDeclaration;
use App\Models\StaticGlobalArtifactChange;
use App\Services\Prebid\PrebidManager;
use Database\Seeders\PrebidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class SupplyChainPublicationTriggerTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_bidder_ads_txt_record_change_triggers_global_static_outbox(): void
    {
        [$admin, $site] = $this->context();
        $this->seed(PrebidSeeder::class);
        $bidder = PrebidBidder::withoutGlobalScopes()->where('code', 'msft')->firstOrFail();
        $account = app(PrebidManager::class)->addAccount($bidder, ['name' => 'Task 37 bidder', 'enabled' => true], $admin);
        app(PrebidManager::class)->assignToSite($account, $site, ['enabled' => true], $admin);
        StaticGlobalArtifactChange::query()->delete();

        BidderAdsTxtRecord::withoutGlobalScopes()->create([
            'organization_id' => $account->organization_id,
            'bidder_account_id' => $account->id,
            'site_id' => $site->id,
            'advertising_system_domain' => 'bidder.example',
            'publisher_account_id' => 'seat-37',
            'relationship' => 'DIRECT',
            'raw_record' => 'bidder.example, seat-37, DIRECT',
            'record_hash' => hash('sha256', 'bidder.example, seat-37, DIRECT'),
            'status' => 'ACTIVE',
            'review_status' => 'VERIFIED',
        ]);

        $change = StaticGlobalArtifactChange::query()->sole();
        $this->assertSame('SUPPLY_CHAIN', $change->artifact_type);
        $this->assertSame('NORMAL', $change->priority->value);
    }

    public function test_seller_identity_change_triggers_global_static_outbox_without_site_config_version(): void
    {
        [, $site] = $this->context();
        StaticGlobalArtifactChange::query()->delete();
        $beforeVersions = $site->configVersions()->count();

        SellerDeclaration::withoutGlobalScopes()->create([
            'organization_id' => $site->publisher->organization_id,
            'publisher_id' => $site->publisher_id,
            'site_id' => null,
            'seller_id' => 'task37-seller',
            'seller_type' => 'PUBLISHER',
            'ads_txt_relationship' => 'DIRECT',
            'name' => $site->publisher->legal_name,
            'domain' => $site->publisher->business_domain,
            'is_confidential' => false,
            'status' => 'DISABLED',
            'review_status' => 'REVIEW_REQUIRED',
        ]);

        $this->assertSame(1, StaticGlobalArtifactChange::query()->count());
        $this->assertSame($beforeVersions, $site->configVersions()->count());
    }

    private function context(): array
    {
        $this->seedIdentity();
        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher, 'Task 37 publisher'), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser, [
            'business_domain' => 'task37-publisher.example',
            'legal_name' => 'Task 37 Publisher LLC',
        ]);
        $site = $this->makeSiteFor($publisher, $publisherUser, ['primary_domain' => 'task37-site.example']);

        return [$admin, $site];
    }
}
