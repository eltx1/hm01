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

    public function test_routes_legacy_report_query_to_soap_without_rest_schema_mismatch(): void
    {
        $connection = new GamConnection(['driver' => 'HYBRID']);
        $rest = Mockery::mock(GamConnectorInterface::class);
        $soap = Mockery::mock(GamConnectorInterface::class);
        $rest->shouldReceive('connection')->andReturn($connection);
        $rest->shouldNotReceive('runReport');
        $soap->shouldReceive('runReport')->once()->with(
            Mockery::on(fn (array $query): bool => ($query['reportCurrency'] ?? null) === 'USD'
                && isset($query['columns'])
                && ! isset($query['metrics'])),
            Mockery::type('array'),
        )->andReturn(GamResult::success(['id' => '77']));

        $result = (new GamHybridConnector($rest, $soap, new GamCapabilityRegistry))->runReport([
            'dimensions' => ['DATE', 'LINE_ITEM_ID'],
            'columns' => ['AD_SERVER_IMPRESSIONS'],
            'dateRangeType' => 'CUSTOM_DATE',
            'reportCurrency' => 'USD',
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('77', $result->data['id']);
    }

    public function test_routes_rest_report_definition_to_rest(): void
    {
        $connection = new GamConnection(['driver' => 'HYBRID']);
        $rest = Mockery::mock(GamConnectorInterface::class);
        $soap = Mockery::mock(GamConnectorInterface::class);
        $rest->shouldReceive('connection')->andReturn($connection);
        $rest->shouldReceive('runReport')->once()->with(
            Mockery::on(fn (array $query): bool => isset($query['metrics']) && isset($query['dateRange'])),
            Mockery::type('array'),
        )->andReturn(GamResult::success(['name' => 'networks/1/reports/2']));
        $soap->shouldNotReceive('runReport');

        $result = (new GamHybridConnector($rest, $soap, new GamCapabilityRegistry))->runReport([
            'metrics' => [['type' => 'IMPRESSIONS']],
            'dateRange' => ['fixed' => ['startDate' => ['year' => 2026, 'month' => 9, 'day' => 1]]],
            'currencyCode' => 'USD',
        ]);

        $this->assertTrue($result->success);
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
