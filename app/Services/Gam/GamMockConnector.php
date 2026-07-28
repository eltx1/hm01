<?php

namespace App\Services\Gam;

use App\Models\GamConnection;
use App\Services\Gam\Contracts\GamConnectorInterface;
use App\Services\Gam\Data\GamResult;

final class GamMockConnector implements GamConnectorInterface
{
    private array $calls = [];
    private int $sequence = 1000;

    public function __construct(private readonly GamConnection $gamConnection)
    {
    }

    public function connection(): GamConnection
    {
        return $this->gamConnection;
    }

    public function calls(): array
    {
        return $this->calls;
    }

    public function testConnection(array $options = []): GamResult { return $this->respond(__FUNCTION__, ['networkCode' => $this->gamConnection->network_code, 'displayName' => 'Mock GAM']); }
    public function getCurrentNetwork(array $options = []): GamResult { return $this->respond(__FUNCTION__, ['networkCode' => $this->gamConnection->network_code, 'displayName' => 'Mock GAM']); }
    public function listAccessibleNetworks(array $options = []): GamResult { return $this->respond(__FUNCTION__, [['networkCode' => $this->gamConnection->network_code, 'displayName' => 'Mock GAM']]); }
    public function getNetworkByCode(string $networkCode, array $options = []): GamResult { return $this->respond(__FUNCTION__, ['networkCode' => $networkCode, 'displayName' => 'Mock GAM']); }
    public function createCompany(array $attributes, array $options = []): GamResult { return $this->created(__FUNCTION__, $attributes); }
    public function updateCompany(array $attributes, array $options = []): GamResult { return $this->respond(__FUNCTION__, $attributes); }
    public function createAdUnit(array $attributes, array $options = []): GamResult { return $this->created(__FUNCTION__, $attributes); }
    public function updateAdUnit(array $attributes, array $options = []): GamResult { return $this->respond(__FUNCTION__, $attributes); }
    public function createPlacement(array $attributes, array $options = []): GamResult { return $this->created(__FUNCTION__, $attributes); }
    public function createCustomTargetingKey(array $attributes, array $options = []): GamResult { return $this->created(__FUNCTION__, $attributes); }
    public function createCustomTargetingValue(array $attributes, array $options = []): GamResult { return $this->created(__FUNCTION__, $attributes); }
    public function createOrder(array $attributes, array $options = []): GamResult { return $this->created(__FUNCTION__, $attributes); }
    public function updateOrder(array $attributes, array $options = []): GamResult { return $this->respond(__FUNCTION__, $attributes); }
    public function createLineItem(array $attributes, array $options = []): GamResult { return $this->created(__FUNCTION__, $attributes); }
    public function updateLineItem(array $attributes, array $options = []): GamResult { return $this->respond(__FUNCTION__, $attributes); }
    public function createCreative(array $attributes, array $options = []): GamResult { return $this->created(__FUNCTION__, $attributes); }
    public function associateCreative(array $attributes, array $options = []): GamResult { return $this->created(__FUNCTION__, $attributes); }
    public function pauseLineItem(array $filterStatement, array $options = []): GamResult { return $this->respond(__FUNCTION__, ['numChanges' => 1, 'statement' => $filterStatement]); }
    public function activateLineItem(array $filterStatement, array $options = []): GamResult { return $this->respond(__FUNCTION__, ['numChanges' => 1, 'statement' => $filterStatement]); }
    public function archiveObject(array $attributes, array $options = []): GamResult { return $this->respond(__FUNCTION__, ['numChanges' => 1, 'attributes' => $attributes]); }
    public function runReport(array $reportQuery, array $options = []): GamResult { return $this->created(__FUNCTION__, ['status' => 'IN_PROGRESS', 'reportQuery' => $reportQuery]); }
    public function getObjectByRemoteId(string $service, string $remoteId, array $options = []): GamResult { return $this->respond(__FUNCTION__, ['service' => $service, 'id' => $remoteId]); }

    private function created(string $method, array $data): GamResult
    {
        return $this->respond($method, array_merge(['id' => (string) ++$this->sequence], $data));
    }

    private function respond(string $method, array $data): GamResult
    {
        $this->calls[] = ['method' => $method, 'data' => $data];

        return GamResult::success($data);
    }
}
