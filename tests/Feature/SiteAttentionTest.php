<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\MonetizationHealthState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class SiteAttentionTest extends TestCase
{
    use RefreshDatabase, InteractsWithIdentity, InteractsWithPublisherSites;

    public function test_queue_uses_stored_evidence_without_private_details_or_writes_and_rejects_publishers(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $owner = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($owner);
        foreach (['Finding' => 'BROKEN', 'Stale' => 'HEALTHY', 'Missing' => null] as $name => $status) {
            $site = $this->makeSiteFor($publisher, $owner, ['display_name' => 'Queue'.$name]);
            $site->update(['status' => 'ACTIVE']);
            if ($status) MonetizationHealthState::create(['organization_id' => $site->organization_id, 'site_id' => $site->id,
                'state_key' => 'renderer_conflict', 'status' => $status, 'details' => ['token' => 'PRIVATE_TOKEN'],
                'observed_at' => $name === 'Stale' ? now()->subHours(2) : now()]);
        }
        $before = MonetizationHealthState::get()->toJson();
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        foreach (['findings' => 'Finding', 'stale' => 'Stale', 'missing' => 'Missing'] as $filter => $expected) {
            $r = $this->get(route('admin.operations.site-attention', ['evidence' => $filter]))->assertOk()->assertSee('Queue'.$expected)->assertDontSee('PRIVATE_TOKEN');
            foreach (array_diff(['Finding', 'Stale', 'Missing'], [$expected]) as $other) $r->assertDontSee('Queue'.$other);
        }
        $this->assertSame($before, MonetizationHealthState::get()->toJson());
        $this->actingAs($owner)->get(route('admin.operations.site-attention'))->assertForbidden();
    }
    public function test_publication_queue_ignores_old_failures_and_in_flight_retries(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $owner = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($owner);
        foreach (['Failed' => 'FAILED', 'Retry' => 'RETRY_SCHEDULED', 'Uploading' => 'UPLOADING', 'Recovered' => 'DEPLOYED'] as $name => $delivery) {
            $site = $this->makeSiteFor($publisher, $owner, ['display_name' => 'Delivery'.$name]);
            $site->update(['status' => 'ACTIVE']);
            foreach ([1, 2] as $number) {
                $version = \App\Models\ConfigVersion::withoutGlobalScopes()->create([
                    'organization_id' => $site->organization_id, 'site_id' => $site->id, 'site_config_id' => $site->siteConfig->id,
                    'environment' => 'PRODUCTION', 'version' => $number,
                    'status' => $number === 2 && $name === 'Recovered' ? 'DEPLOYED' : 'DELIVERY_FAILED',
                    'payload' => ['secret' => 'PRIVATE_PUBLICATION_PAYLOAD'], 'checksum' => str_repeat('a', 64),
                    'file_path' => 'test.json', 'created_by' => $admin->id,
                ]);
                \App\Models\StaticDeliveryItem::withoutGlobalScopes()->create([
                    'organization_id' => $site->organization_id, 'site_id' => $site->id, 'config_version_id' => $version->id,
                    'environment' => 'PRODUCTION', 'status' => $number === 1 ? 'FAILED' : $delivery,
                    'available_at' => now(), 'priority' => 'NORMAL', 'checksum' => str_repeat('a', 64), 'created_by' => $admin->id,
                ]);
            }
            $site->siteConfig->update(['production_version' => $name === 'Recovered' ? 2 : 1]);
        }
        $before = \App\Models\StaticDeliveryItem::withoutGlobalScopes()->get()->toJson();
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.operations.site-attention', ['evidence' => 'publication']))->assertOk()
            ->assertSee('DeliveryFailed')->assertSee('DeliveryRetry')->assertSee('Publication retry scheduled')
            ->assertDontSee('DeliveryUploading')->assertDontSee('DeliveryRecovered')->assertDontSee('PRIVATE_PUBLICATION_PAYLOAD');
        $this->assertSame($before, \App\Models\StaticDeliveryItem::withoutGlobalScopes()->get()->toJson());
    }

    public function test_latest_source_queue_replaces_old_failures_and_excludes_other_domains_and_preview(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $owner = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($owner);
        foreach (['Failed', 'Recovered'] as $label) {
            $site = $this->makeSiteFor($publisher, $owner, ['display_name' => 'Source'.$label]);
            $site->update(['status' => 'ACTIVE']);
            $domain = $site->domains()->firstOrFail();
            $domain->update(['domain' => $site->primary_domain, 'is_primary' => true]);
            foreach ([1, 2] as $attempt) {
                \App\Models\SiteVerification::create(['site_id' => $site->id, 'organization_id' => $site->organization_id,
                    'site_domain_id' => $domain->id, 'method' => 'ADS_TXT', 'status' => $label === 'Recovered' && $attempt === 2 ? 'VERIFIED' : 'FAILED',
                    'expected_value' => 'PRIVATE_RECORD', 'evidence' => ['url' => 'PRIVATE_URL'], 'attempted_at' => now()->subMinutes(3 - $attempt)]);
                \App\Models\SyntheticProbeResult::create(['site_id' => $site->id, 'organization_id' => $site->organization_id,
                    'probe' => 'PRIVATE_PROBE', 'environment' => 'PRODUCTION', 'status' => $label === 'Recovered' && $attempt === 2 ? 'PASS' : 'FAIL',
                    'checks' => ['token' => 'PRIVATE_TOKEN'], 'observed_at' => now()->subMinutes(3 - $attempt)]);
            }
            if ($label === 'Recovered') {
                \App\Models\SyntheticProbeResult::create(['site_id' => $site->id, 'organization_id' => $site->organization_id,
                    'probe' => 'PRIVATE_PROBE', 'environment' => 'PREVIEW', 'status' => 'FAIL', 'observed_at' => now()]);
                $removed = $site->domains()->create(['organization_id' => $site->organization_id, 'domain' => 'removed.example', 'verification_token' => 'PRIVATE_REMOVED_TOKEN', 'is_primary' => false]);
                \App\Models\SiteVerification::create(['site_id' => $site->id, 'organization_id' => $site->organization_id,
                    'site_domain_id' => $removed->id, 'expected_value' => 'PRIVATE_REMOVED_RECORD', 'method' => 'ADS_TXT', 'status' => 'FAILED', 'attempted_at' => now()]);
            }
        }
        $before = \App\Models\SiteVerification::get()->toJson();
        $probesBefore = \App\Models\SyntheticProbeResult::get()->toJson();
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        foreach (['ads_txt', 'synthetic'] as $filter) {
            \Illuminate\Support\Facades\DB::enableQueryLog();
            \Illuminate\Support\Facades\DB::flushQueryLog();
            $this->get(route('admin.operations.site-attention', ['evidence' => $filter]))->assertOk()
                ->assertSee('SourceFailed')->assertDontSee('SourceRecovered')->assertDontSee('PRIVATE_');
            $sourceQueries = collect(\Illuminate\Support\Facades\DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "site_verifications"') || str_contains($q['query'], 'from `site_verifications`'));
            $this->assertLessThanOrEqual(3, $sourceQueries->count());
            \Illuminate\Support\Facades\DB::disableQueryLog();
        }
        $this->assertSame($before, \App\Models\SiteVerification::get()->toJson());
        $this->assertSame($probesBefore, \App\Models\SyntheticProbeResult::get()->toJson());
    }

}
