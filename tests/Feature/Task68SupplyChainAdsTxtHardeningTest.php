<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\SupplyChainReviewStatus;
use App\Models\AuditLog;
use App\Models\PlatformAdsTxtRecord;
use App\Models\PublisherContract;
use App\Services\Compliance\AdsTxtComplianceService;
use App\Services\SupplyChain\HorusSellerIdentityService;
use App\Services\SupplyChain\PlatformAdsTxtFileEditorService;
use App\Services\SupplyChain\SupplyChainArtifactBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class Task68SupplyChainAdsTxtHardeningTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
    }

    public function test_multiple_express_publishers_remain_discoverable_with_public_name_and_domain(): void
    {
        [$publisherA, $userA, $siteA] = $this->publisherAndSite('Alpha Media LLC', 'alpha.example');
        [$publisherB, $userB, $siteB] = $this->publisherAndSite('Beta Media LLC', 'beta.example');
        $identities = app(HorusSellerIdentityService::class);
        $hmpA = $identities->ensureForPublisher($publisherA, $userA);
        $hmsA = $identities->ensureForSite($siteA, $userA);
        $hmpB = $identities->ensureForPublisher($publisherB, $userB);
        $hmsB = $identities->ensureForSite($siteB, $userB);

        $rows = collect(app(SupplyChainArtifactBuilder::class)->sellersJsonPayload()['sellers'])->keyBy('seller_id');
        foreach ([
            [$hmpA->seller_id, 'Alpha Media LLC', 'alpha.example'],
            [$hmsA->seller_id, 'Alpha Media LLC', 'alpha.example'],
            [$hmpB->seller_id, 'Beta Media LLC', 'beta.example'],
            [$hmsB->seller_id, 'Beta Media LLC', 'beta.example'],
        ] as [$sellerId, $name, $domain]) {
            $this->assertTrue($rows->has($sellerId), 'Expected '.$sellerId.' to remain in sellers.json.');
            $this->assertSame('PUBLISHER', $rows[$sellerId]['seller_type']);
            $this->assertSame($name, $rows[$sellerId]['name']);
            $this->assertSame($domain, $rows[$sellerId]['domain']);
            $this->assertSame(0, $rows[$sellerId]['is_confidential']);
        }
    }

    public function test_commercial_term_changes_do_not_disable_or_reopen_seller_identity_review(): void
    {
        [$publisher, $user, $site] = $this->publisherAndSite('Stable Publisher LLC', 'stable.example');
        $identities = app(HorusSellerIdentityService::class);
        $hmp = $identities->ensureForPublisher($publisher, $user);
        $hms = $identities->ensureForSite($site, $user);
        foreach ([$hmp, $hms] as $seller) {
            $seller->forceFill([
                'status' => 'ACTIVE',
                'review_status' => SupplyChainReviewStatus::Verified,
                'reviewed_at' => now(),
            ])->saveQuietly();
        }
        $admin = $this->admin();
        $contract = PublisherContract::withoutGlobalScopes()->create([
            'organization_id' => $publisher->organization_id,
            'publisher_id' => $publisher->id,
            'contract_reference' => 'TASK68-COMMERCIAL',
            'status' => 'ACTIVE',
            'starts_at' => now()->subDay(),
            'revenue_share_percent' => 70,
            'currency' => 'USD',
            'payment_terms' => 'NET_30',
            'created_by' => $admin->id,
        ]);
        $contract->update(['revenue_share_percent' => 75, 'payment_terms' => 'NET_45']);

        foreach ([$hmp, $hms] as $seller) {
            $seller->refresh();
            $this->assertSame('ACTIVE', $seller->status->value);
            $this->assertSame(SupplyChainReviewStatus::Verified, $seller->review_status);
        }
    }

    public function test_pending_website_uses_horus_activation_records_not_placeholder_as_required_file(): void
    {
        [$publisher, $user, $site] = $this->publisherAndSite('Activation Publisher LLC', 'activation.example');
        $identities = app(HorusSellerIdentityService::class);
        $hmp = $identities->ensureForPublisher($publisher, $user);
        $hms = $identities->ensureForSite($site, $user);
        $site->update(['status' => 'PENDING_REVIEW']);
        PlatformAdsTxtRecord::create([
            'advertising_system_domain' => 'master.example.com',
            'publisher_account_id' => 'master-seat',
            'relationship' => 'RESELLER',
            'raw_record' => 'master.example.com, master-seat, RESELLER',
            'record_hash' => hash('sha256', 'master.example.com, master-seat, RESELLER'),
            'status' => 'ACTIVE',
            'review_status' => SupplyChainReviewStatus::Verified,
        ]);

        $canonical = app(AdsTxtComplianceService::class)->canonical($site->fresh());
        $this->assertSame('ACTIVATION', $canonical['phase']);
        $this->assertSame(2, $canonical['required_record_count']);
        $this->assertStringContainsString('horusmedia.net, '.$hmp->seller_id.', DIRECT', $canonical['content']);
        $this->assertStringContainsString('horusmedia.net, '.$hms->seller_id.', DIRECT', $canonical['content']);
        $this->assertStringContainsString('master.example.com, master-seat, RESELLER', $canonical['content']);
        $this->assertStringNotContainsString('master.example.com, master-seat, RESELLER', $canonical['comparison_content']);
        $this->assertStringNotContainsString('placeholder.example.com, placeholder, DIRECT, placeholder', $canonical['content']);
    }

    public function test_master_file_editor_previews_and_atomically_applies_add_change_and_soft_remove(): void
    {
        $admin = $this->admin();
        $first = $this->master('alpha.exchange.com', 'seat-a', 'DIRECT');
        $second = $this->master('beta.exchange.com', 'seat-b', 'RESELLER');
        $editor = app(PlatformAdsTxtFileEditorService::class);
        $target = implode("\n", [
            'beta.exchange.com, seat-b, DIRECT',
            'gamma.exchange.com, seat-c, RESELLER, cert-c',
            'gamma.exchange.com, seat-c, RESELLER, cert-c',
        ]);

        $preview = $editor->preview($target);
        $this->assertSame(2, $preview['current_count']);
        $this->assertSame(2, $preview['target_count']);
        $this->assertSame(1, $preview['added_count']);
        $this->assertSame(1, $preview['removed_count']);
        $this->assertSame(1, $preview['changed_count']);
        $this->assertSame(0, $preview['unchanged_count']);
        $this->assertSame(0, $preview['invalid_count']);
        $this->assertSame(1, $preview['duplicates']);

        $editor->replace($target, $admin, 'Replace the reviewed partner master file.');
        $this->assertSame('DISABLED', $first->refresh()->status);
        $this->assertSame('ACTIVE', $second->refresh()->status);
        $this->assertSame('DIRECT', $second->relationship);
        $this->assertDatabaseHas('platform_ads_txt_records', [
            'advertising_system_domain' => 'gamma.exchange.com',
            'publisher_account_id' => 'seat-c',
            'relationship' => 'RESELLER',
            'status' => 'ACTIVE',
            'review_status' => SupplyChainReviewStatus::Verified->value,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'supply_chain.platform_ads_txt.file_replaced', 'actor_id' => $admin->id]);
        $this->assertStringNotContainsString('alpha.exchange.com, seat-a, DIRECT', $editor->currentFile());
        $this->assertStringContainsString('gamma.exchange.com, seat-c, RESELLER, cert-c', $editor->currentFile());
    }

    public function test_master_file_editor_rejects_conflicting_duplicate_identity_before_apply(): void
    {
        $editor = app(PlatformAdsTxtFileEditorService::class);
        $preview = $editor->preview(implode("\n", [
            'conflict.exchange.com, seat-x, DIRECT',
            'conflict.exchange.com, seat-x, RESELLER',
        ]));

        $this->assertSame(1, $preview['invalid_count']);
        $this->assertStringContainsString('conflicting fields', $preview['invalid'][0]['message']);
    }

    private function publisherAndSite(string $name, string $domain): array
    {
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher, $name), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($user, [
            'legal_name' => $name,
            'display_name' => $name,
            'business_domain' => $domain,
        ]);
        $site = $this->makeSiteFor($publisher, $user, [
            'display_name' => $name.' Website',
            'primary_domain' => $domain,
        ]);

        return [$publisher, $user, $site];
    }

    private function admin()
    {
        return $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media'), RoleName::SuperAdmin);
    }

    private function master(string $domain, string $seller, string $relationship): PlatformAdsTxtRecord
    {
        $line = $domain.', '.$seller.', '.$relationship;

        return PlatformAdsTxtRecord::create([
            'advertising_system_domain' => $domain,
            'publisher_account_id' => $seller,
            'relationship' => $relationship,
            'raw_record' => $line,
            'record_hash' => hash('sha256', $line),
            'status' => 'ACTIVE',
            'review_status' => SupplyChainReviewStatus::Verified,
        ]);
    }
}
