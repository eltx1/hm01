<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Services\Gam\GamConnectorManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class GamRestConnectorTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, RefreshDatabase;

    public function test_rest_connector_reads_network_and_writes_ad_unit_without_dated_version(): void
    {
        $this->seedIdentity();
        $organization = $this->makeOrganization(OrganizationType::HorusMedia);
        $actor = $this->makeUser($organization, RoleName::SuperAdmin);
        $connection = $this->makeGamConnection($organization, $actor, [
            'driver' => 'REST', 'network_code' => '123456789', 'dry_run_default' => false,
        ]);
        $this->cacheToken($connection);
        Http::fake([
            'https://admanager.googleapis.com/v1/networks/123456789' => Http::response(['name' => 'networks/123456789', 'networkCode' => '123456789']),
            'https://admanager.googleapis.com/v1/networks/123456789/adUnits' => Http::response(['name' => 'networks/123456789/adUnits/42'], 200),
        ]);

        $connector = app(GamConnectorManager::class)->for($connection);
        $network = $connector->getCurrentNetwork();
        $adUnit = $connector->createAdUnit(['displayName' => 'Article top', 'adUnitCode' => 'article_top'], ['dry_run' => false]);

        $this->assertTrue($network->success);
        $this->assertTrue($adUnit->success);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://admanager.googleapis.com/v1/networks/123456789/adUnits'
            && ! str_contains($request->url(), 'v202'));
    }

    public function test_rest_dry_run_is_audited_without_external_request(): void
    {
        $this->seedIdentity();
        $organization = $this->makeOrganization(OrganizationType::HorusMedia);
        $actor = $this->makeUser($organization, RoleName::SuperAdmin);
        $connection = $this->makeGamConnection($organization, $actor, ['driver' => 'REST', 'dry_run_default' => true]);
        Http::fake();

        $result = app(GamConnectorManager::class)->for($connection)->createPlacement(['displayName' => 'Homepage']);

        $this->assertTrue($result->dryRun);
        Http::assertNothingSent();
        $this->assertDatabaseHas('gam_api_operations', ['operation' => 'createPlacement', 'service' => 'REST:placements', 'status' => 'DRY_RUN']);
    }

    public function test_rest_order_batch_uses_the_google_aip_requests_envelope(): void
    {
        $this->seedIdentity();
        $organization = $this->makeOrganization(OrganizationType::HorusMedia);
        $actor = $this->makeUser($organization, RoleName::SuperAdmin);
        $connection = $this->makeGamConnection($organization, $actor, [
            'driver' => 'REST', 'network_code' => '123456789', 'dry_run_default' => false,
        ]);
        $this->cacheToken($connection);
        Http::fake([
            'https://admanager.googleapis.com/v1/networks/123456789/orders:batchCreate' => Http::response([
                'orders' => [['name' => 'networks/123456789/orders/77']],
            ]),
        ]);

        $result = app(GamConnectorManager::class)->for($connection)->createOrder([
            'displayName' => 'Horus campaign',
            'advertiser' => 'networks/123456789/companies/1',
            'trafficker' => 'networks/123456789/users/2',
        ], ['dry_run' => false]);

        $this->assertTrue($result->success);
        Http::assertSent(fn ($request) => $request->url() === 'https://admanager.googleapis.com/v1/networks/123456789/orders:batchCreate'
            && data_get($request->data(), 'requests.0.parent') === 'networks/123456789'
            && data_get($request->data(), 'requests.0.order.displayName') === 'Horus campaign');
    }

    public function test_rest_custom_targeting_translates_soap_neutral_names_to_v1_resources(): void
    {
        $this->seedIdentity();
        $organization = $this->makeOrganization(OrganizationType::HorusMedia);
        $actor = $this->makeUser($organization, RoleName::SuperAdmin);
        $connection = $this->makeGamConnection($organization, $actor, [
            'driver' => 'HYBRID', 'network_code' => '123456789', 'dry_run_default' => false,
        ]);
        $this->cacheToken($connection);
        Http::fake([
            'https://admanager.googleapis.com/v1/networks/123456789/customTargetingKeys' => Http::response(['name' => 'networks/123456789/customTargetingKeys/11']),
            'https://admanager.googleapis.com/v1/networks/123456789/customTargetingValues' => Http::response(['name' => 'networks/123456789/customTargetingValues/22']),
        ]);
        $connector = app(GamConnectorManager::class)->for($connection);

        $key = $connector->createCustomTargetingKey(['name' => 'hb_pb', 'displayName' => 'Price', 'type' => 'PREDEFINED', 'reportableType' => 'ON'], ['dry_run' => false]);
        $value = $connector->createCustomTargetingValue(['customTargetingKeyId' => $key->data['id'], 'name' => '1.00', 'displayName' => '1.00', 'matchType' => 'EXACT'], ['dry_run' => false]);

        $this->assertSame('11', $key->data['id']);
        $this->assertSame('22', $value->data['id']);
        Http::assertSent(fn ($request) => data_get($request->data(), 'adTagName') === 'hb_pb' && ! array_key_exists('name', $request->data()));
        Http::assertSent(fn ($request) => data_get($request->data(), 'customTargetingKey') === 'networks/123456789/customTargetingKeys/11'
            && data_get($request->data(), 'adTagName') === '1.00');
    }

    public function test_unpublished_rest_write_is_planned_through_audited_soap_fallback(): void
    {
        $this->seedIdentity();
        $organization = $this->makeOrganization(OrganizationType::HorusMedia);
        $actor = $this->makeUser($organization, RoleName::SuperAdmin);
        $connection = $this->makeGamConnection($organization, $actor, ['driver' => 'REST']);
        Http::fake();

        $result = app(GamConnectorManager::class)->for($connection)->createCreative([
            '__type' => 'ThirdPartyCreative', 'name' => 'Creative',
        ], ['dry_run' => true]);

        $this->assertTrue($result->success);
        $this->assertTrue($result->dryRun);
        $this->assertDatabaseHas('gam_api_operations', [
            'operation' => 'createCreative',
            'service' => 'SOAP:CreativeService',
            'method' => 'createCreatives',
            'status' => 'DRY_RUN',
        ]);
        Http::assertNothingSent();
    }

    private function cacheToken($connection): void
    {
        $credential = $connection->credential;
        $key = 'gam:oauth:'.hash('sha256', $connection->id.'|'.($credential->rotated_at?->timestamp ?? '0'));
        Cache::put($key, Crypt::encryptString('test-access-token'), now()->addMinutes(30));
    }
}
