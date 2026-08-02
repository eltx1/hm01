<?php

namespace Tests\Feature;

use App\Enums\GamConnectionType;
use App\Enums\GamCredentialType;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\GamApiOperation;
use App\Models\GamConnection;
use App\Models\GamRemoteObject;
use App\Services\Gam\GamConnectionResolver;
use App\Services\Gam\GamConnectionService;
use App\Services\Gam\GamConnectorManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class GamIntegrationTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_first_horus_connection_is_primary_and_another_can_replace_it(): void
    {
        $this->seedIdentity();
        $organization = $this->makeOrganization(OrganizationType::HorusMedia);
        $admin = $this->makeUser($organization, RoleName::SuperAdmin);
        $service = app(GamConnectionService::class);

        $first = $service->create($this->connectionData($organization->id, 'Horus Primary', GamConnectionType::HorusGam), $admin);
        $second = $service->create($this->connectionData($organization->id, 'Horus Secondary', GamConnectionType::HorusGam), $admin);

        $this->assertTrue($first->is_primary);
        $this->assertFalse($second->is_primary);

        $service->setPrimary($second, $admin);

        $this->assertFalse($first->refresh()->is_primary);
        $this->assertTrue($second->refresh()->is_primary);
        $this->assertSame(1, GamConnection::withoutGlobalScopes()->where('type', GamConnectionType::HorusGam->value)->where('is_primary', true)->count());
    }

    public function test_approved_horus_site_automatically_resolves_primary_horus_connection(): void
    {
        $this->seedIdentity();
        $horus = $this->makeOrganization(OrganizationType::HorusMedia);
        $admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $primary = $this->makeGamConnection($horus, $admin, ['type' => GamConnectionType::HorusGam, 'is_primary' => true]);

        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($publisherUser), $publisherUser);
        $site->update(['status' => SiteStatus::Approved]);

        $resolved = app(GamConnectionResolver::class)->resolve($site->refresh());

        $this->assertSame(ServingMode::HorusGam, $site->serving_mode);
        $this->assertSame($primary->id, $resolved?->id);
    }

    public function test_assigning_optional_connection_changes_only_selected_website(): void
    {
        $this->seedIdentity();
        $horus = $this->makeOrganization(OrganizationType::HorusMedia);
        $admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $this->makeGamConnection($horus, $admin, ['type' => GamConnectionType::HorusGam, 'is_primary' => true]);
        $partner = $this->makeGamConnection($horus, $admin, ['type' => GamConnectionType::McmPartnerGam, 'is_primary' => false]);

        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser);
        $first = $this->makeSiteFor($publisher, $publisherUser);
        $second = $this->makeSiteFor($publisher, $publisherUser);

        app(GamConnectionService::class)->assignToSite($first, $partner, $admin, 'Use partner GAM for this site only.');

        $this->assertSame($partner->id, $first->refresh()->gam_connection_id);
        $this->assertSame(ServingMode::McmPartnerGam, $first->serving_mode);
        $this->assertNull($second->refresh()->gam_connection_id);
        $this->assertSame(ServingMode::HorusGam, $second->serving_mode);
        $this->assertSame(2, $first->servingSettings->configuration_version);
        $this->assertSame(1, $second->servingSettings->configuration_version);
    }

    public function test_dry_run_does_not_call_google_and_real_write_is_idempotent_and_mapped(): void
    {
        $this->seedIdentity();
        $organization = $this->makeOrganization(OrganizationType::HorusMedia);
        $admin = $this->makeUser($organization, RoleName::SuperAdmin);
        $connection = $this->makeGamConnection($organization, $admin, ['dry_run_default' => true]);
        $this->cacheToken($connection);
        Http::fake([
            'https://admanager.googleapis.com/v1/networks/*/adUnits' => Http::response(['name' => 'networks/'.$connection->network_code.'/adUnits/9001']),
        ]);
        $connector = app(GamConnectorManager::class)->for($connection);
        $attributes = ['displayName' => 'Article top', 'adUnitCode' => 'article_top'];
        $options = [
            'local_type' => 'ad_unit',
            'local_id' => 'local-ad-unit-1',
            'remote_type' => 'ad_unit',
            'remote_id_path' => 'name',
        ];

        $planned = $connector->createAdUnit($attributes, $options);
        $this->assertTrue($planned->dryRun);
        Http::assertNothingSent();

        $created = $connector->createAdUnit($attributes, array_merge($options, ['dry_run' => false]));
        $duplicate = $connector->createAdUnit($attributes, array_merge($options, ['dry_run' => false]));

        $this->assertTrue($created->success);
        $this->assertTrue($duplicate->duplicate);
        Http::assertSentCount(1);
        $this->assertSame(1, GamApiOperation::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('gam_api_operations', ['id' => $created->operationId, 'status' => 'SUCCEEDED', 'attempts' => 1]);
        $this->assertDatabaseHas('gam_remote_objects', [
            'gam_connection_id' => $connection->id,
            'local_object_type' => 'ad_unit',
            'local_object_id' => 'local-ad-unit-1',
            'remote_object_type' => 'ad_unit',
            'remote_object_id' => 'networks/'.$connection->network_code.'/adUnits/9001',
        ]);
        $this->assertSame(1, GamRemoteObject::withoutGlobalScopes()->count());
    }

    public function test_api_operation_logs_never_store_credentials(): void
    {
        $this->seedIdentity();
        $organization = $this->makeOrganization(OrganizationType::HorusMedia);
        $admin = $this->makeUser($organization, RoleName::SuperAdmin);
        $connection = $this->makeGamConnection($organization, $admin, ['dry_run_default' => false]);
        $this->cacheToken($connection);
        Http::fake([
            'https://admanager.googleapis.com/v1/networks/*/placements' => Http::response(['name' => 'networks/'.$connection->network_code.'/placements/9001']),
        ]);
        $connector = app(GamConnectorManager::class)->for($connection);

        $connector->createPlacement([
            'displayName' => 'Safe placement',
            'client_secret' => 'must-not-be-stored',
            'nested' => ['access_token' => 'must-also-disappear'],
        ], ['dry_run' => false]);

        $operation = GamApiOperation::withoutGlobalScopes()->firstOrFail();
        $encoded = json_encode([$operation->request_payload, $operation->response_payload], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('must-not-be-stored', $encoded);
        $this->assertStringNotContainsString('must-also-disappear', $encoded);
        $this->assertStringContainsString('[REDACTED]', $encoded);
    }

    public function test_connection_test_synchronizes_health_networks_and_permissions(): void
    {
        $this->seedIdentity();
        $organization = $this->makeOrganization(OrganizationType::HorusMedia);
        $admin = $this->makeUser($organization, RoleName::SuperAdmin);
        $connection = $this->makeGamConnection($organization, $admin, ['network_code' => '123456789', 'dry_run_default' => true]);
        $this->cacheToken($connection);
        Http::fake([
            'https://admanager.googleapis.com/v1/networks/123456789' => Http::response([
                'name' => 'networks/123456789', 'networkCode' => '123456789', 'displayName' => 'Horus Media GAM',
            ]),
            'https://admanager.googleapis.com/v1/networks' => Http::response(['networks' => [[
                'name' => 'networks/123456789', 'networkCode' => '123456789', 'displayName' => 'Horus Media GAM',
            ]]]),
        ]);

        $result = app(GamConnectionService::class)->test($connection, $admin);

        $this->assertTrue($result->success);
        $this->assertSame('HEALTHY', $connection->refresh()->health_status->value);
        $this->assertNotNull($connection->last_successful_sync_at);
        $this->assertDatabaseHas('gam_networks', ['gam_connection_id' => $connection->id, 'network_code' => '123456789']);
        $this->assertDatabaseHas('gam_connection_permissions', ['gam_connection_id' => $connection->id, 'permission_name' => 'api.access', 'status' => 'GRANTED']);
        $this->assertDatabaseHas('gam_connection_permissions', ['gam_connection_id' => $connection->id, 'permission_name' => 'network.read', 'status' => 'GRANTED']);
    }

    public function test_only_authorized_administrator_can_open_connection_dashboard(): void
    {
        $this->seedIdentity();
        $horus = $this->makeOrganization(OrganizationType::HorusMedia);
        $admin = $this->makeUser($horus, RoleName::AdOpsAdmin);
        $publisher = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])->get(route('admin.gam.connections.index'))->assertOk()->assertSee('Google Ad Manager connections');
        $this->actingAs($publisher)->get(route('admin.gam.connections.index'))->assertForbidden();
    }

    private function connectionData(string $organizationId, string $name, GamConnectionType $type): array
    {
        return [
            'organization_id' => $organizationId,
            'name' => $name,
            'type' => $type->value,
            'credential_type' => GamCredentialType::ServiceAccount->value,
            'driver' => 'REST',
            'network_code' => fake()->unique()->numerify('#########'),
            'application_name' => 'Horus Media Test',
            'is_enabled' => true,
            'dry_run_default' => true,
            'credential_reference' => 'env:GAM_TEST_CREDENTIAL_PATH',
            'client_email_hint' => 'gam-test@project.iam.gserviceaccount.com',
            'scopes' => [config('gam.oauth.scope')],
        ];
    }

    private function cacheToken(GamConnection $connection): void
    {
        $credential = $connection->credential;
        $key = 'gam:oauth:'.hash('sha256', $connection->id.'|'.($credential->rotated_at?->timestamp ?? '0'));
        Cache::put($key, Crypt::encryptString('test-access-token'), now()->addMinutes(30));
    }
}
