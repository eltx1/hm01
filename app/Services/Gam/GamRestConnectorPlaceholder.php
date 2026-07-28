<?php

namespace App\Services\Gam;

use App\Models\GamConnection;
use App\Services\Gam\Contracts\GamConnectorInterface;
use App\Services\Gam\Data\GamResult;

final class GamRestConnectorPlaceholder implements GamConnectorInterface
{
    public function __construct(private readonly GamConnection $gamConnection)
    {
    }

    public function connection(): GamConnection
    {
        return $this->gamConnection;
    }

    public function testConnection(array $options = []): GamResult { return $this->unsupported(); }
    public function getCurrentNetwork(array $options = []): GamResult { return $this->unsupported(); }
    public function listAccessibleNetworks(array $options = []): GamResult { return $this->unsupported(); }
    public function getNetworkByCode(string $networkCode, array $options = []): GamResult { return $this->unsupported(); }
    public function createCompany(array $attributes, array $options = []): GamResult { return $this->unsupported(); }
    public function updateCompany(array $attributes, array $options = []): GamResult { return $this->unsupported(); }
    public function createAdUnit(array $attributes, array $options = []): GamResult { return $this->unsupported(); }
    public function updateAdUnit(array $attributes, array $options = []): GamResult { return $this->unsupported(); }
    public function createPlacement(array $attributes, array $options = []): GamResult { return $this->unsupported(); }
    public function createCustomTargetingKey(array $attributes, array $options = []): GamResult { return $this->unsupported(); }
    public function createCustomTargetingValue(array $attributes, array $options = []): GamResult { return $this->unsupported(); }
    public function createOrder(array $attributes, array $options = []): GamResult { return $this->unsupported(); }
    public function updateOrder(array $attributes, array $options = []): GamResult { return $this->unsupported(); }
    public function createLineItem(array $attributes, array $options = []): GamResult { return $this->unsupported(); }
    public function updateLineItem(array $attributes, array $options = []): GamResult { return $this->unsupported(); }
    public function createCreative(array $attributes, array $options = []): GamResult { return $this->unsupported(); }
    public function associateCreative(array $attributes, array $options = []): GamResult { return $this->unsupported(); }
    public function pauseLineItem(array $filterStatement, array $options = []): GamResult { return $this->unsupported(); }
    public function activateLineItem(array $filterStatement, array $options = []): GamResult { return $this->unsupported(); }
    public function archiveObject(array $attributes, array $options = []): GamResult { return $this->unsupported(); }
    public function runReport(array $reportQuery, array $options = []): GamResult { return $this->unsupported(); }
    public function getObjectByRemoteId(string $service, string $remoteId, array $options = []): GamResult { return $this->unsupported(); }

    private function unsupported(): GamResult
    {
        return GamResult::failure(
            'CONFIGURATION',
            'REST_CONNECTOR_NOT_ENABLED',
            'The Ad Manager REST connector is intentionally isolated until the beta surface is enabled for this deployment.',
        );
    }
}
