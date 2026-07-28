<?php

namespace Tests\Unit;

use App\Enums\GamConnectionType;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Services\Gam\GamMockConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class GamMockConnectorTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, RefreshDatabase;

    public function test_mock_connector_implements_every_api_operation(): void
    {
        $this->seedIdentity();
        $organization = $this->makeOrganization(OrganizationType::HorusMedia);
        $actor = $this->makeUser($organization, RoleName::SuperAdmin);
        $connection = $this->makeGamConnection($organization, $actor, ['type' => GamConnectionType::HorusGam]);
        $connector = new GamMockConnector($connection);

        $results = [
            $connector->testConnection(),
            $connector->getCurrentNetwork(),
            $connector->listAccessibleNetworks(),
            $connector->getNetworkByCode('123456789'),
            $connector->createCompany(['name' => 'Advertiser']),
            $connector->updateCompany(['id' => '1', 'name' => 'Advertiser 2']),
            $connector->createAdUnit(['name' => 'Top']),
            $connector->updateAdUnit(['id' => '2', 'name' => 'Top 2']),
            $connector->createPlacement(['name' => 'Article']),
            $connector->createCustomTargetingKey(['name' => 'hb_pb']),
            $connector->createCustomTargetingValue(['customTargetingKeyId' => '3', 'name' => '1.00']),
            $connector->createOrder(['name' => 'Order']),
            $connector->updateOrder(['id' => '4', 'name' => 'Order 2']),
            $connector->createLineItem(['name' => 'Line Item']),
            $connector->updateLineItem(['id' => '5', 'name' => 'Line Item 2']),
            $connector->createCreative(['name' => 'Creative']),
            $connector->associateCreative(['lineItemId' => '5', 'creativeId' => '6']),
            $connector->pauseLineItem(['query' => 'WHERE id = 5']),
            $connector->activateLineItem(['query' => 'WHERE id = 5']),
            $connector->archiveObject(['service' => 'OrderService']),
            $connector->runReport(['dimensions' => ['DATE']]),
            $connector->getObjectByRemoteId('OrderService', '4'),
        ];

        foreach ($results as $result) {
            $this->assertTrue($result->success);
        }

        $this->assertCount(22, $connector->calls());
        $this->assertSame($connection->id, $connector->connection()->id);
    }
}
