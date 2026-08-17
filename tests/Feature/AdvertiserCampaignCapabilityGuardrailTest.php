<?php

namespace Tests\Feature;

use App\Enums\CampaignDeliveryCapabilityStatus;
use App\Enums\CampaignStatus;
use App\Enums\GamConnectionType;
use App\Enums\GamHealthStatus;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Models\Advertiser;
use App\Models\GamRemoteObject;
use App\Models\HorusNotification;
use App\Services\Campaigns\AdvertiserAccountService;
use App\Services\Campaigns\CampaignCreativeService;
use App\Services\Campaigns\CampaignDeliveryCapabilityService;
use App\Services\Campaigns\CampaignDeploymentService;
use App\Services\Campaigns\CampaignWorkflowService;
use App\Services\Inventory\InventoryManager;
use App\Services\Operations\PlatformControlService;
use App\Services\Settings\GlobalSettingsService;
use App\Services\Settings\TypedSettingsRegistry;
use Database\Seeders\SettingsAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class AdvertiserCampaignCapabilityGuardrailTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_eligible_gam_campaign_is_available_and_draft_can_exist_before_delivery(): void
    {
        $fx = $this->fixture();
        $result = app(CampaignDeliveryCapabilityService::class)->evaluate($fx['campaign'], true);

        $this->assertSame(CampaignDeliveryCapabilityStatus::Available, $result->status);
        $this->assertTrue($result->available());
        $this->assertSame('GAM', $result->backend);
        $this->assertSame(CampaignStatus::Draft, $fx['campaign']->status);
    }

    public function test_horus_direct_publisher_site_does_not_become_an_advertiser_delivery_backend(): void
    {
        $fx = $this->fixture();
        $fx['site']->update(['serving_mode' => ServingMode::HorusDirect, 'gam_connection_id' => null]);

        $result = app(CampaignDeliveryCapabilityService::class)->evaluate($fx['campaign'], true);

        $this->assertSame(CampaignDeliveryCapabilityStatus::NoGamBackend, $result->status);
        $this->assertSame(ServingMode::HorusDirect, $fx['site']->fresh()->serving_mode);
    }

    public function test_disabled_and_unhealthy_selected_gam_connections_are_blocked_with_exact_status(): void
    {
        $disabled = $this->fixture();
        $disabled['gam']->update(['is_enabled' => false]);
        $this->assertSame(
            CampaignDeliveryCapabilityStatus::GamConnectionDisabled,
            app(CampaignDeliveryCapabilityService::class)->evaluate($disabled['campaign'], true)->status,
        );

        $failed = $this->fixture();
        $failed['gam']->update(['health_status' => GamHealthStatus::Failed]);
        $this->assertSame(
            CampaignDeliveryCapabilityStatus::GamConnectionUnhealthy,
            app(CampaignDeliveryCapabilityService::class)->evaluate($failed['campaign'], true)->status,
        );
    }

    public function test_operational_gam_kill_blocks_new_delivery(): void
    {
        $fx = $this->fixture();
        app(PlatformControlService::class)->set('PLATFORM', null, 'GAM', true, 'Task 41 test kill switch', $fx['admin']);

        $result = app(CampaignDeliveryCapabilityService::class)->evaluate($fx['campaign'], true);

        $this->assertSame(CampaignDeliveryCapabilityStatus::GamOperationallyDisabled, $result->status);
        $this->assertFalse($result->available());
    }

    public function test_wrong_connection_context_and_missing_remote_mapping_are_not_treated_as_available(): void
    {
        $wrong = $this->fixture();
        $wrong['site']->update(['serving_mode' => ServingMode::McmPartnerGam]);
        $wrongResult = app(CampaignDeliveryCapabilityService::class)->evaluate($wrong['campaign'], true);
        $this->assertSame(CampaignDeliveryCapabilityStatus::ConfigurationIncomplete, $wrongResult->status);
        $this->assertSame('GAM_CONNECTION_CONTEXT_MISMATCH', $wrongResult->reasons[0]['code']);

        $missing = $this->fixture();
        GamRemoteObject::withoutGlobalScopes()
            ->where('gam_connection_id', $missing['gam']->id)
            ->where('local_object_type', 'ad_unit')
            ->delete();
        $this->assertSame(
            CampaignDeliveryCapabilityStatus::RemoteMappingIncomplete,
            app(CampaignDeliveryCapabilityService::class)->evaluate($missing['campaign'], true)->status,
        );
    }

    public function test_feature_off_blocks_new_creation_and_submission_but_keeps_existing_campaign_readable(): void
    {
        $fx = $this->fixture();
        app(GlobalSettingsService::class)->set($fx['admin'], 'advertiser_campaigns.enabled', false, 'Pilot closed for Task 41 test');

        try {
            app(CampaignWorkflowService::class)->create($fx['advertiser'], [], $fx['advertiserUser']);
            $this->fail('Feature-off campaign creation must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('campaign_feature', $exception->errors());
        }

        try {
            app(CampaignWorkflowService::class)->submit($fx['campaign']->fresh(), $fx['advertiserUser']);
            $this->fail('Feature-off submission must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('delivery_capability', $exception->errors());
        }

        $this->assertSame(CampaignStatus::Draft, $fx['campaign']->fresh()->status);
        $this->assertSame(
            $fx['campaign']->id,
            $fx['advertiser']->campaigns()->withoutGlobalScopes()->findOrFail($fx['campaign']->id)->id,
        );
    }

    public function test_draft_can_be_saved_without_backend_but_cannot_be_submitted(): void
    {
        $fx = $this->fixture();
        $fx['site']->update(['serving_mode' => ServingMode::HorusDirect, 'gam_connection_id' => null]);

        $this->assertSame(CampaignStatus::Draft, $fx['campaign']->status);
        $this->assertSame(
            CampaignDeliveryCapabilityStatus::NoGamBackend,
            app(CampaignDeliveryCapabilityService::class)->evaluate($fx['campaign'], true)->status,
        );

        $this->expectException(ValidationException::class);
        app(CampaignWorkflowService::class)->submit($fx['campaign']->fresh(), $fx['advertiserUser']);
    }

    public function test_admin_approval_and_scheduling_cannot_bypass_lost_capability(): void
    {
        $approval = $this->fixture();
        $workflow = app(CampaignWorkflowService::class);
        $submitted = $workflow->submit($approval['campaign'], $approval['advertiserUser']);
        $workflow->reviewCreative($approval['creative']->fresh(), true, $approval['admin']);
        $approval['gam']->update(['is_enabled' => false]);
        try {
            $workflow->approve($submitted->fresh(), $approval['admin']);
            $this->fail('Approval must not bypass unavailable delivery.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('delivery_capability', $exception->errors());
        }
        $this->assertSame(CampaignStatus::PendingReview, $submitted->fresh()->status);
        $this->assertDatabaseMissing('advertiser_invoices', ['campaign_id' => $submitted->id]);

        $scheduling = $this->fixture();
        $submitted = $workflow->submit($scheduling['campaign'], $scheduling['advertiserUser']);
        $workflow->reviewCreative($scheduling['creative']->fresh(), true, $scheduling['admin']);
        $approved = $workflow->approve($submitted->fresh(), $scheduling['admin']);
        $scheduling['gam']->update(['is_enabled' => false]);
        try {
            $workflow->schedule($approved->fresh(), $scheduling['admin']);
            $this->fail('Scheduling must not bypass unavailable delivery.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('delivery_capability', $exception->errors());
        }
        $this->assertSame(CampaignStatus::Approved, $approved->fresh()->status);
    }

    public function test_resume_activation_cannot_bypass_lost_capability(): void
    {
        $fx = $this->fixture();
        $active = $this->advanceToActive($fx);
        $paused = app(CampaignWorkflowService::class)->pause($active, $fx['admin']);
        $fx['gam']->update(['is_enabled' => false]);

        try {
            app(CampaignWorkflowService::class)->resume($paused->fresh(), $fx['admin']);
            $this->fail('Resume must not bypass unavailable delivery.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('delivery_capability', $exception->errors());
        }

        $this->assertSame(CampaignStatus::Paused, $paused->fresh()->status);
    }

    public function test_direct_deployment_call_fails_before_external_gam_campaign_writes(): void
    {
        $fx = $this->fixture();
        $active = $this->advanceToActive($fx);
        $fx['gam']->update(['is_enabled' => false]);

        $before = GamRemoteObject::withoutGlobalScopes()
            ->where('gam_connection_id', $fx['gam']->id)
            ->whereIn('local_object_type', ['advertiser', 'campaign', 'campaign_network_instance', 'campaign_creative', 'campaign_creative_association'])
            ->count();

        try {
            app(CampaignDeploymentService::class)->deployCampaign($active->fresh(), $fx['admin'], false, true);
            $this->fail('Direct deployment must be rejected before GAM writes.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('delivery_capability', $exception->errors());
        }

        $after = GamRemoteObject::withoutGlobalScopes()
            ->where('gam_connection_id', $fx['gam']->id)
            ->whereIn('local_object_type', ['advertiser', 'campaign', 'campaign_network_instance', 'campaign_creative', 'campaign_creative_association'])
            ->count();
        $this->assertSame($before, $after);
        $this->assertSame(0, $after);
    }

    public function test_existing_deployed_campaign_can_still_pause_without_deleting_remote_objects_or_finance(): void
    {
        $fx = $this->fixture();
        $active = $this->advanceToActive($fx);
        $deployment = app(CampaignDeploymentService::class);
        $result = $deployment->deployCampaign($active->fresh(), $fx['admin'], false, true);
        $this->assertFalse(collect($result['results'])->contains(fn (array $row) => ! $row['success']));

        $remoteBefore = GamRemoteObject::withoutGlobalScopes()->where('gam_connection_id', $fx['gam']->id)->count();
        $invoiceBefore = $active->invoices()->withoutGlobalScopes()->firstOrFail()->only(['id', 'status', 'total_minor']);

        app(PlatformControlService::class)->set('PLATFORM', null, 'GAM', true, 'Block new delivery only', $fx['admin']);
        $paused = app(CampaignWorkflowService::class)->pause($active->fresh(), $fx['admin']);
        $pauseResults = $deployment->pauseAll($paused->load('networkInstances.connection'), $fx['admin']);

        $this->assertNotContains(false, $pauseResults);
        $this->assertSame($remoteBefore, GamRemoteObject::withoutGlobalScopes()->where('gam_connection_id', $fx['gam']->id)->count());
        $this->assertSame($invoiceBefore, $active->invoices()->withoutGlobalScopes()->firstOrFail()->only(['id', 'status', 'total_minor']));
    }

    public function test_customer_projection_hides_gam_identity_while_admin_projection_contains_exact_backend_details(): void
    {
        $fx = $this->fixture();
        $result = app(CampaignDeliveryCapabilityService::class)->evaluate($fx['campaign'], true);

        $customer = json_encode($result->forCustomer(), JSON_THROW_ON_ERROR);
        $admin = $result->toArray();

        $this->assertStringNotContainsString($fx['gam']->id, $customer);
        $this->assertStringNotContainsString($fx['gam']->network_code, $customer);
        $this->assertSame('GAM', $admin['backend']);
        $this->assertSame($fx['gam']->id, $admin['networks'][0]['connection_id']);
        $this->assertSame($fx['gam']->network_code, $admin['networks'][0]['network_code']);
    }

    public function test_feature_setting_is_high_impact_permission_protected_audited_and_delivery_warning_is_deduplicated(): void
    {
        $fx = $this->fixture();
        $this->seed(SettingsAccessSeeder::class);
        $definition = app(TypedSettingsRegistry::class)->get('advertiser_campaigns.enabled');
        $this->assertSame('boolean', $definition->type);
        $this->assertTrue($definition->runtimeEditable);
        $this->assertTrue($definition->highImpact);

        $publisherOrg = $this->makeOrganization(OrganizationType::Publisher, 'Settings Denied Publisher');
        $publisherUser = $this->makeUser($publisherOrg, RoleName::PublisherAdmin);
        $this->actingAs($publisherUser)
            ->put(route('admin.settings.update', ['key' => 'advertiser_campaigns.enabled']), ['value' => false])
            ->assertForbidden();

        app(GlobalSettingsService::class)->set($fx['admin'], 'advertiser_campaigns.enabled', false, 'Task 41 pilot control');
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'advertiser_campaigns.enabled_changed',
            'actor_id' => $fx['admin']->id,
        ]);

        app(GlobalSettingsService::class)->set($fx['admin'], 'advertiser_campaigns.enabled', true, 'Reopen Task 41 pilot');
        $active = $this->advanceToActive($fx);
        app(PlatformControlService::class)->set('PLATFORM', null, 'GAM', true, 'Capability warning test', $fx['admin']);
        $capability = app(CampaignDeliveryCapabilityService::class);
        $blocked = $capability->evaluate($active->fresh());
        $this->assertSame(CampaignDeliveryCapabilityStatus::GamOperationallyDisabled, $blocked->status);
        $capability->warnIfUnavailable($active->fresh(), $blocked);
        $capability->warnIfUnavailable($active->fresh(), $blocked);

        $this->assertSame(1, HorusNotification::query()
            ->where('type', 'campaign.delivery_backend_unavailable')
            ->where('related_id', $active->id)
            ->where('recipient_id', $fx['admin']->id)
            ->count());
    }

    private function advanceToActive(array $fx)
    {
        $workflow = app(CampaignWorkflowService::class);
        $submitted = $workflow->submit($fx['campaign']->fresh(), $fx['advertiserUser']);
        $workflow->reviewCreative($fx['creative']->fresh(), true, $fx['admin']);
        $approved = $workflow->approve($submitted->fresh(), $fx['admin']);

        return $workflow->schedule($approved->fresh(), $fx['admin']);
    }

    private function fixture(): array
    {
        $this->seedIdentity();
        $horusOrg = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Task 41');
        $admin = $this->makeUser($horusOrg, RoleName::SuperAdmin, ['password' => Hash::make('Task41Pass123!')]);
        $gam = $this->makeGamConnection($horusOrg, $admin, [
            'type' => GamConnectionType::HorusGam,
            'driver' => 'MOCK',
            'network_code' => '111111111',
            'is_primary' => true,
            'dry_run_default' => false,
        ]);

        $publisherOrg = $this->makeOrganization(OrganizationType::Publisher, 'Task 41 Publisher');
        $publisherUser = $this->makeUser($publisherOrg, RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser, ['display_name' => 'Task 41 Publisher']);
        $site = $this->makeSiteFor($publisher, $publisherUser, [
            'display_name' => 'Task 41 Site',
            'primary_domain' => 'task41-site.test',
            'serving_mode' => ServingMode::HorusGam,
            'gam_connection_id' => $gam->id,
        ]);

        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($site, [
            'name' => 'Top',
            'code' => 'task41-top',
            'sizes' => [['width' => 300, 'height' => 250]],
        ], $admin);
        $placement = $inventory->createPlacement($site, [
            'name' => 'Top',
            'code' => 'task41-top',
            'type' => 'DISPLAY',
            'status' => 'ACTIVE',
            'ad_unit_id' => $adUnit->id,
            'sizes' => [['width' => 300, 'height' => 250]],
        ], $admin);
        $this->mapAdUnit($gam, $adUnit->id, '54101');

        $advertiserOrg = $this->makeOrganization(OrganizationType::Advertiser, 'Task 41 Advertiser');
        $advertiserUser = $this->makeUser($advertiserOrg, RoleName::AdvertiserAdmin);
        $advertiser = Advertiser::withoutGlobalScopes()->create([
            'organization_id' => $advertiserOrg->id,
            'legal_name' => 'Task 41 Advertiser LLC',
            'display_name' => 'Task 41 Advertiser',
            'status' => 'ACTIVE',
            'billing_email' => 'billing-task41@advertiser.test',
        ]);
        $accounts = app(AdvertiserAccountService::class);
        $accounts->linkUser($advertiser, $advertiserUser, 'OWNER', true, $admin);
        $accounts->saveBillingProfile($advertiser, [
            'legal_name' => 'Task 41 Advertiser LLC',
            'billing_email' => 'billing-task41@advertiser.test',
            'currency' => 'USD',
            'country_code' => 'EG',
            'address_line_1' => 'Cairo',
            'city' => 'Cairo',
            'payment_terms_days' => 15,
            'is_default' => true,
            'status' => 'ACTIVE',
        ], $advertiserUser);

        $workflow = app(CampaignWorkflowService::class);
        $campaign = $workflow->create($advertiser, [
            'name' => 'Task 41 capability campaign',
            'objective' => 'Awareness',
            'pricing_model' => 'CPM',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDays(10),
            'currency' => 'USD',
            'total_budget_minor' => 100000,
            'daily_budget_minor' => 10000,
            'unit_price_minor' => 250,
            'impression_goal' => 100000,
            'click_goal' => 1000,
            'countries' => [],
            'devices' => [],
            'site_ids' => [$site->id],
            'placement_ids' => [$placement->id],
            'frequency_cap_impressions' => 3,
            'frequency_cap_days' => 1,
            'landing_url' => 'https://advertiser.test/task41',
        ], $advertiserUser);
        $creative = app(CampaignCreativeService::class)->create($campaign, [
            'name' => 'Task 41 text creative',
            'type' => 'TEXT',
            'text_content' => 'Task 41 offer',
            'click_through_url' => 'https://advertiser.test/task41',
        ], null, $advertiserUser);

        return compact('admin', 'gam', 'publisherUser', 'site', 'advertiser', 'advertiserUser', 'campaign', 'creative');
    }

    private function mapAdUnit($connection, string $localId, string $remoteId): void
    {
        GamRemoteObject::withoutGlobalScopes()->create([
            'organization_id' => $connection->organization_id,
            'gam_connection_id' => $connection->id,
            'local_object_type' => 'ad_unit',
            'local_object_id' => $localId,
            'remote_object_type' => 'ad_unit',
            'remote_object_id' => $remoteId,
            'idempotency_key' => 'task41-'.$remoteId,
            'payload_hash' => hash('sha256', $remoteId),
            'remote_status' => 'ACTIVE',
            'synced_at' => now(),
        ]);
    }
}
