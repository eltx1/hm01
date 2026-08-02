<?php

namespace Tests\Unit;

use App\Models\GamConnection;
use App\Services\Gam\Contracts\GamConnectorInterface;
use App\Services\Gam\Data\GamResult;
use App\Services\Gam\GamCapabilityRegistry;
use App\Services\Gam\GamHybridConnector;
use Mockery;
use Tests\TestCase;

class GamHybridConnectorTest extends TestCase
{
    public function test_routes_supported_operation_to_rest_and_unpublished_write_to_soap(): void
    {
        $connection = new GamConnection(['driver' => 'HYBRID']);
        $rest = Mockery::mock(GamConnectorInterface::class);
        $soap = Mockery::mock(GamConnectorInterface::class);
        $rest->shouldReceive('connection')->andReturn($connection);
        $rest->shouldReceive('createOrder')->once()->andReturn(GamResult::success(['id' => '7']));
        $soap->shouldReceive('createLineItem')->once()->andReturn(GamResult::success(['id' => '8']));

        $connector = new GamHybridConnector($rest, $soap, new GamCapabilityRegistry);

        $this->assertSame('7', $connector->createOrder(['name' => 'Order'])->data['id']);
        $this->assertSame('8', $connector->createLineItem(['name' => 'Line'])->data['id']);
    }

    public function test_does_not_replay_a_failed_rest_write_through_soap(): void
    {
        $connection = new GamConnection(['driver' => 'HYBRID']);
        $rest = Mockery::mock(GamConnectorInterface::class);
        $soap = Mockery::mock(GamConnectorInterface::class);
        $rest->shouldReceive('connection')->andReturn($connection);
        $rest->shouldReceive('createOrder')->once()->andReturn(GamResult::failure('AUTH', 'DENIED', 'Denied'));
        $soap->shouldNotReceive('createOrder');

        $result = (new GamHybridConnector($rest, $soap, new GamCapabilityRegistry))->createOrder(['name' => 'Order']);

        $this->assertFalse($result->success);
        $this->assertSame('DENIED', $result->errorCode);
    }

    public function test_contextual_archive_routes_legacy_action_to_soap(): void
    {
        $connection = new GamConnection(['driver' => 'HYBRID']);
        $rest = Mockery::mock(GamConnectorInterface::class);
        $soap = Mockery::mock(GamConnectorInterface::class);
        $rest->shouldReceive('connection')->andReturn($connection);
        $soap->shouldReceive('archiveObject')->once()->andReturn(GamResult::success(['numChanges' => 1]));

        $result = (new GamHybridConnector($rest, $soap, new GamCapabilityRegistry))->archiveObject([
            'service' => 'CreativeService', 'method' => 'performCreativeAction',
        ]);

        $this->assertTrue($result->success);
    }
}
