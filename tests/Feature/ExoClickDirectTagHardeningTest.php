<?php

namespace Tests\Feature;

use App\Enums\DemandAccountScope;
use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\DemandNetwork;
use App\Services\Demand\DemandAccountService;
use App\Services\Demand\DemandConnectorManager;
use Database\Seeders\DemandNetworkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

final class ExoClickDirectTagHardeningTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    public function test_exoclick_requires_the_provider_issued_container_class_before_a_recipe_is_safe(): void
    {
        $this->seedIdentity();
        $this->seed(DemandNetworkSeeder::class);

        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $network = DemandNetwork::query()->where('code', 'EXOCLICK')->firstOrFail();

        $account = app(DemandAccountService::class)->create([
            'organization_id' => $horus->id,
            'demand_network_id' => $network->id,
            'name' => 'ExoClick hardening test',
            'scope' => DemandAccountScope::HorusMedia,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'is_default' => false,
            'revenue_share_percent' => 20,
            'fallback_priority' => 10,
            'account_identifier' => 'TEST_ONLY_PUBLIC_ACCOUNT',
            'configuration' => [],
        ], $admin);

        $connector = app(DemandConnectorManager::class)->for($account);
        $script = '<script async type="application/javascript" src="https://a.magsrv.com/ad-provider.js"></script>';
        $queue = '<script>(AdProvider = window.AdProvider || []).push({"serve": {}});</script>';

        $safe = $connector->parseDirectTag($script.'<ins class="TEST_PROVIDER_CLASS" data-zoneid="TEST_ZONE_ID"></ins>'.$queue);
        $this->assertTrue($safe['safe']);
        $this->assertSame('ins', data_get($safe, 'recipe.container.element'));
        $this->assertSame('TEST_PROVIDER_CLASS', data_get($safe, 'recipe.container.class'));

        $missingClass = $connector->parseDirectTag($script.'<ins data-zoneid="TEST_ZONE_ID"></ins>'.$queue);
        $this->assertFalse($missingClass['safe']);
        $this->assertContains(
            'ExoClick asynchronous banner tags require the provider-issued container class.',
            $missingClass['securityWarnings'],
        );
    }
}
