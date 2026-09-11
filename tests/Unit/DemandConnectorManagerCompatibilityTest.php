<?php

namespace Tests\Unit;

use App\Models\DemandAccount;
use App\Models\DemandNetwork;
use App\Services\Demand\ConfiguredDemandConnector;
use App\Services\Demand\CustomThirdPartyTagConnector;
use App\Services\Demand\DemandConnectorManager;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

final class DemandConnectorManagerCompatibilityTest extends TestCase
{
    public function test_existing_custom_third_party_network_rows_use_the_dedicated_connector_without_database_mutation(): void
    {
        $network = new DemandNetwork([
            'code' => 'CUSTOM_THIRD_PARTY_TAG',
            'name' => 'Custom Third-Party Tag',
            'connector_class' => ConfiguredDemandConnector::class,
            'supports_direct_js' => true,
        ]);

        $account = new DemandAccount([
            'name' => 'Existing production account',
            'integration_mode' => 'MANUAL_TAG',
            'configuration' => [],
        ]);
        $account->setRelation('network', $network);
        $account->setRelation('credentials', new Collection());

        $connector = app(DemandConnectorManager::class)->for($account);

        $this->assertInstanceOf(CustomThirdPartyTagConnector::class, $connector);
        $this->assertSame(ConfiguredDemandConnector::class, $network->connector_class);
    }
}
