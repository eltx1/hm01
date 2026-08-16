<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\ContractStatus;
use App\Enums\OrganizationType;
use App\Enums\PublisherApplicationStatus;
use App\Enums\RoleName;
use App\Enums\SellerDeclarationStatus;
use App\Enums\SellerIdentitySource;
use App\Enums\SupplyChainReviewStatus;
use App\Models\PublisherApplication;
use App\Models\PublisherContract;
use App\Models\SellerDeclaration;
use App\Services\SupplyChain\HorusSellerIdentityService;
use App\Services\SupplyChain\SupplyChainArtifactBuilder;
use App\Services\SupplyChain\SupplyChainInvariantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class HorusPublisherSellerIdentityLifecycleTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_approval_reserves_exactly_one_disabled_managed_publisher_identity(): void
    {
        [$publisher, $publisherUser, $admin] = $this->basePublisher();
        $application = PublisherApplication::withoutGlobalScopes()->create([
            'organization_id' => $publisher->organization_id,
            'publisher_id' => $publisher->id,
            'applicant_user_id' => $publisherUser->id,
            'primary_domain' => $publisher->business_domain,
            'status' => PublisherApplicationStatus::UnderReview,
            'current_revision' => 1,
            'submitted_at' => now(),
        ]);

        $application->update([
            'status' => PublisherApplicationStatus::Approved,
            'approved_at' => now(),
            'reviewed_at' => now(),
            'reviewed_by' => $admin->id,
        ]);

        $seller = SellerDeclaration::withoutGlobalScopes()->where('publisher_id', $publisher->id)->sole();

        $this->assertSame(SellerIdentitySource::HorusManaged, $seller->identity_source);
        $this->assertSame(SellerDeclarationStatus::Disabled, $seller->status);
        $this->assertSame(SupplyChainReviewStatus::ReviewRequired, $seller->review_status);
        $this->assertNull($seller->site_id);
        $this->assertSame('PUBLISHER', $seller->seller_type);
        $this->assertSame('DIRECT', $seller->ads_txt_relationship);
        $this->assertFalse($seller->is_confidential);
        $this->assertMatchesRegularExpression('/^HMP-[0-9A-HJKMNP-TV-Z]{26}$/', $seller->seller_id);
        $this->assertLessThanOrEqual(64, strlen($seller->seller_id));
        $this->assertNotSame($publisher->id, $seller->seller_id);
        $this->assertNotSame($publisherUser->id, $seller->seller_id);

        $again = app(HorusSellerIdentityService::class)->ensureForPublisher($publisher, $admin);
        $this->assertSame($seller->id, $again->id);
        $this->assertSame(1, SellerDeclaration::withoutGlobalScopes()
            ->where('publisher_id', $publisher->id)
            ->where('identity_source', SellerIdentitySource::HorusManaged->value)
            ->whereNull('site_id')
            ->count());
    }

    public function test_multiple_users_and_multiple_sites_share_one_default_horus_seller_id(): void
    {
        [$publisher, $publisherUser] = $this->basePublisher();
        $secondUser = $this->makeUser($publisherUser->organization, RoleName::PublisherAdmin);
        $this->makeSiteFor($publisher, $publisherUser, ['primary_domain' => 'site-a.example']);
        $this->makeSiteFor($publisher, $secondUser, ['primary_domain' => 'site-b.example']);
        $this->makeSiteFor($publisher, $publisherUser, ['primary_domain' => 'site-c.example']);

        $seller = app(HorusSellerIdentityService::class)->ensureForPublisher($publisher);

        $this->assertNull($seller->site_id);
        $this->assertSame(1, SellerDeclaration::withoutGlobalScopes()
            ->where('publisher_id', $publisher->id)
            ->where('identity_source', SellerIdentitySource::HorusManaged->value)
            ->count());
    }

    public function test_activation_requires_commercial_and_supply_chain_review(): void
    {
        [$publisher, , $admin] = $this->basePublisher();
        $seller = app(HorusSellerIdentityService::class)->ensureForPublisher($publisher, $admin);
        $invariants = app(SupplyChainInvariantService::class);

        try {
            $invariants->changeSellerStatus($seller, SellerDeclarationStatus::Active, $admin);
            $this->fail('Application approval alone must not activate monetization.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $invariants->reviewPublisherIdentity($publisher, SupplyChainReviewStatus::Verified, $admin);
        $invariants->reviewSellerDeclaration($seller->refresh(), SupplyChainReviewStatus::Verified, $admin);

        try {
            $invariants->changeSellerStatus($seller->refresh(), SellerDeclarationStatus::Active, $admin);
            $this->fail('A current commercial contract is required.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('SIGNED or ACTIVE Publisher contract', implode(' ', $exception->errors()['status']));
        }

        PublisherContract::withoutGlobalScopes()->create([
            'organization_id' => $publisher->organization_id,
            'publisher_id' => $publisher->id,
            'contract_reference' => 'TASK33-REPRESENTATION',
            'status' => ContractStatus::Active,
            'starts_at' => now()->subDay(),
            'revenue_share_percent' => 70,
            'currency' => 'USD',
            'payment_terms' => 'NET_30',
            'created_by' => $admin->id,
        ]);

        $this->assertSame(SupplyChainReviewStatus::ReviewRequired, $seller->refresh()->review_status);
        $invariants->reviewSellerDeclaration($seller->refresh(), SupplyChainReviewStatus::Verified, $admin);
        $active = $invariants->changeSellerStatus($seller->refresh(), SellerDeclarationStatus::Active, $admin);

        $this->assertSame(SellerDeclarationStatus::Active, $active->status);
    }

    public function test_managed_public_id_is_immutable_non_recyclable_and_hmp_namespace_is_reserved(): void
    {
        [$publisher] = $this->basePublisher();
        $seller = app(HorusSellerIdentityService::class)->ensureForPublisher($publisher);
        $original = $seller->seller_id;

        foreach ([
            ['seller_id' => 'HMP-01ARZ3NDEKTSV4RRFFQ69G5FAV'],
            ['publisher_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAA'],
            ['site_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAB'],
            ['identity_source' => SellerIdentitySource::Manual],
        ] as $mutation) {
            try {
                $seller->refresh()->update($mutation);
                $this->fail('Managed identity mutation should be rejected.');
            } catch (LogicException) {
                $this->assertSame($original, $seller->refresh()->seller_id);
            }
        }

        try {
            $seller->refresh()->delete();
            $this->fail('Managed identities must never be deleted or recycled.');
        } catch (LogicException) {
            $this->assertDatabaseHas('seller_declarations', ['id' => $seller->id, 'seller_id' => $original]);
        }

        $this->expectException(LogicException::class);
        SellerDeclaration::withoutGlobalScopes()->create([
            'organization_id' => $publisher->organization_id,
            'publisher_id' => $publisher->id,
            'site_id' => null,
            'identity_source' => SellerIdentitySource::Manual,
            'seller_id' => 'HMP-01ARZ3NDEKTSV4RRFFQ69G5FAC',
            'seller_type' => 'PUBLISHER',
            'ads_txt_relationship' => 'DIRECT',
            'name' => 'Manual Collision',
            'domain' => 'publisher-owner.example',
        ]);
    }

    public function test_different_publishers_cannot_share_a_managed_public_id(): void
    {
        [$publisher] = $this->basePublisher();
        $seller = app(HorusSellerIdentityService::class)->ensureForPublisher($publisher);

        $secondOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Second Publisher');
        $secondUser = $this->makeUser($secondOrganization, RoleName::PublisherAdmin);
        $secondPublisher = $this->makePublisherFor($secondUser, [
            'legal_name' => 'Second Publisher LLC',
            'business_domain' => 'second-owner.example',
        ]);

        $this->expectException(LogicException::class);
        SellerDeclaration::withoutGlobalScopes()->create([
            'organization_id' => $secondPublisher->organization_id,
            'publisher_id' => $secondPublisher->id,
            'site_id' => null,
            'identity_source' => SellerIdentitySource::HorusManaged,
            'identity_issued_at' => now(),
            'seller_id' => $seller->seller_id,
            'seller_type' => 'PUBLISHER',
            'ads_txt_relationship' => 'DIRECT',
            'name' => $secondPublisher->legal_name,
            'domain' => $secondPublisher->business_domain,
        ]);
    }

    public function test_active_managed_identity_drives_one_sellers_entry_and_same_ads_txt_and_schain_sid_for_all_sites(): void
    {
        [$publisher, $publisherUser, $admin] = $this->basePublisher();
        $siteA = $this->makeSiteFor($publisher, $publisherUser, ['primary_domain' => 'alpha-site.example']);
        $siteB = $this->makeSiteFor($publisher, $publisherUser, ['primary_domain' => 'beta-site.example']);
        $seller = $this->activate($publisher, $admin);
        $invariants = app(SupplyChainInvariantService::class);

        $first = $invariants->sellers();
        $second = $invariants->sellers();

        $this->assertCount(1, $first['sellers']);
        $this->assertSame($seller->seller_id, data_get($first, 'sellers.0.payload.seller_id'));
        $this->assertSame(data_get($first, 'sellers.0.payload'), data_get($second, 'sellers.0.payload'));

        foreach ([$siteA, $siteB] as $site) {
            $ads = $invariants->adsTxtForSite($site, $first);
            $schain = $invariants->schainForSite($site, $first);

            $this->assertContains('horusmedia.net, '.$seller->seller_id.', DIRECT', $ads['lines']);
            $this->assertSame(1, $schain['complete']);
            $this->assertSame([[
                'asi' => 'horusmedia.net',
                'sid' => $seller->seller_id,
                'hp' => 1,
            ]], $schain['nodes']);
        }

        $files = app(SupplyChainArtifactBuilder::class)->files();
        $payload = json_decode($files['supply/sellers.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $payload['sellers']);
        $this->assertSame($seller->seller_id, $payload['sellers'][0]['seller_id']);
        $this->assertSame('PUBLISHER', $payload['sellers'][0]['seller_type']);
        $this->assertSame($publisher->legal_name, $payload['sellers'][0]['name']);
        $this->assertSame($publisher->business_domain, $payload['sellers'][0]['domain']);
        $this->assertSame(0, $payload['sellers'][0]['is_confidential']);
    }

    public function test_legal_identity_domain_and_commercial_changes_reopen_review_without_changing_seller_id(): void
    {
        [$publisher, , $admin] = $this->basePublisher();
        $seller = $this->activate($publisher, $admin);
        $originalId = $seller->seller_id;

        $publisher->update([
            'legal_name' => 'Publisher Holdings LLC',
            'business_domain' => 'new-owner.example',
        ]);

        $seller->refresh();
        $publisher->refresh();
        $this->assertSame($originalId, $seller->seller_id);
        $this->assertSame('Publisher Holdings LLC', $seller->name);
        $this->assertSame('new-owner.example', $seller->domain);
        $this->assertSame(SellerDeclarationStatus::Disabled, $seller->status);
        $this->assertSame(SupplyChainReviewStatus::ReviewRequired, $seller->review_status);
        $this->assertSame(SupplyChainReviewStatus::ReviewRequired, $publisher->supply_chain_review_status);

        app(SupplyChainInvariantService::class)->reviewPublisherIdentity($publisher, SupplyChainReviewStatus::Verified, $admin);
        app(SupplyChainInvariantService::class)->reviewSellerDeclaration($seller, SupplyChainReviewStatus::Verified, $admin);
        app(SupplyChainInvariantService::class)->changeSellerStatus($seller, SellerDeclarationStatus::Active, $admin);

        $contract = $publisher->contracts()->firstOrFail();
        $contract->update(['payment_terms' => 'NET_45']);
        $this->assertSame(SellerDeclarationStatus::Disabled, $seller->refresh()->status);
        $this->assertSame(SupplyChainReviewStatus::ReviewRequired, $seller->review_status);
        $this->assertSame($originalId, $seller->seller_id);
    }

    public function test_suspended_publisher_is_removed_from_public_supply_chain_until_reviewed_reactivation(): void
    {
        [$publisher, $publisherUser, $admin] = $this->basePublisher();
        $site = $this->makeSiteFor($publisher, $publisherUser, ['primary_domain' => 'suspended-site.example']);
        $seller = $this->activate($publisher, $admin);

        $publisher->update(['status' => AccountStatus::Suspended]);

        $this->assertSame(SellerDeclarationStatus::Disabled, $seller->refresh()->status);
        $network = app(SupplyChainInvariantService::class)->sellers();
        $this->assertSame([], $network['sellers']);
        $this->assertSame([], app(SupplyChainInvariantService::class)->schainForSite($site, $network)['nodes']);
    }

    public function test_confidentiality_requires_explicit_horus_admin_review_and_then_normal_seller_review(): void
    {
        [$publisher, , $admin] = $this->basePublisher();
        $seller = app(HorusSellerIdentityService::class)->ensureForPublisher($publisher);
        PublisherContract::withoutGlobalScopes()->create([
            'organization_id' => $publisher->organization_id,
            'publisher_id' => $publisher->id,
            'contract_reference' => 'TASK33-CONF',
            'status' => ContractStatus::Active,
            'starts_at' => now()->subDay(),
            'revenue_share_percent' => 70,
            'currency' => 'USD',
            'payment_terms' => 'NET_30',
            'created_by' => $admin->id,
        ]);
        $invariants = app(SupplyChainInvariantService::class);
        $invariants->reviewPublisherIdentity($publisher, SupplyChainReviewStatus::Verified, $admin);

        $seller->update(['is_confidential' => true]);
        $invariants->reviewSellerDeclaration($seller->refresh(), SupplyChainReviewStatus::Verified, $admin);

        try {
            $invariants->changeSellerStatus($seller->refresh(), SellerDeclarationStatus::Active, $admin);
            $this->fail('Confidentiality must require an explicit Horus admin review.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('confidentiality review', strtolower(implode(' ', $exception->errors()['status'])));
        }

        app(HorusSellerIdentityService::class)->reviewConfidentiality($seller->refresh(), true, $admin);
        $this->assertSame(SupplyChainReviewStatus::ReviewRequired, $seller->refresh()->review_status);
        $invariants->reviewSellerDeclaration($seller, SupplyChainReviewStatus::Verified, $admin);
        $active = $invariants->changeSellerStatus($seller->refresh(), SellerDeclarationStatus::Active, $admin);

        $this->assertTrue($active->is_confidential);
        $payload = data_get($invariants->sellers(), 'sellers.0.payload');
        $this->assertSame(1, $payload['is_confidential']);
        $this->assertNull($payload['name']);
        $this->assertNull($payload['domain']);
    }

    private function basePublisher(): array
    {
        $this->seedIdentity();
        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $organization = $this->makeOrganization(OrganizationType::Publisher, 'Publisher Org');
        $publisherUser = $this->makeUser($organization, RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser, [
            'legal_name' => 'Publisher Legal LLC',
            'display_name' => 'Publisher',
            'business_domain' => 'publisher-owner.example',
            'status' => AccountStatus::Active,
        ]);

        return [$publisher, $publisherUser, $admin];
    }

    private function activate($publisher, $admin): SellerDeclaration
    {
        $seller = app(HorusSellerIdentityService::class)->ensureForPublisher($publisher, $admin);

        PublisherContract::withoutGlobalScopes()->create([
            'organization_id' => $publisher->organization_id,
            'publisher_id' => $publisher->id,
            'contract_reference' => 'TASK33-'.strtoupper(fake()->unique()->bothify('??##??')),
            'status' => ContractStatus::Active,
            'starts_at' => now()->subDay(),
            'revenue_share_percent' => 70,
            'currency' => 'USD',
            'payment_terms' => 'NET_30',
            'created_by' => $admin->id,
        ]);

        $invariants = app(SupplyChainInvariantService::class);
        $invariants->reviewPublisherIdentity($publisher, SupplyChainReviewStatus::Verified, $admin);
        $invariants->reviewSellerDeclaration($seller->refresh(), SupplyChainReviewStatus::Verified, $admin);

        return $invariants->changeSellerStatus($seller->refresh(), SellerDeclarationStatus::Active, $admin);
    }
}
