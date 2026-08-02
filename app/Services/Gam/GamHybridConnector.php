<?php

namespace App\Services\Gam;

use App\Models\GamConnection;
use App\Services\Gam\Contracts\GamConnectorInterface;
use App\Services\Gam\Data\GamResult;

/**
 * Selects a transport before sending a request. It intentionally never retries
 * a failed REST write through SOAP because the first write may have succeeded.
 */
final class GamHybridConnector implements GamConnectorInterface
{
    public function __construct(
        private readonly GamConnectorInterface $rest,
        private readonly GamConnectorInterface $soap,
        private readonly GamCapabilityRegistry $capabilities,
    ) {}

    public function connection(): GamConnection { return $this->rest->connection(); }
    public function testConnection(array $options = []): GamResult { return $this->route(__FUNCTION__, [], $options); }
    public function getCurrentNetwork(array $options = []): GamResult { return $this->route(__FUNCTION__, [], $options); }
    public function listAccessibleNetworks(array $options = []): GamResult { return $this->route(__FUNCTION__, [], $options); }
    public function getNetworkByCode(string $networkCode, array $options = []): GamResult { return $this->route(__FUNCTION__, [$networkCode], $options); }
    public function createCompany(array $attributes, array $options = []): GamResult { return $this->route(__FUNCTION__, [$attributes], $options); }
    public function updateCompany(array $attributes, array $options = []): GamResult { return $this->route(__FUNCTION__, [$attributes], $options); }
    public function createAdUnit(array $attributes, array $options = []): GamResult { return $this->route(__FUNCTION__, [$attributes], $options); }
    public function updateAdUnit(array $attributes, array $options = []): GamResult { return $this->route(__FUNCTION__, [$attributes], $options); }
    public function createPlacement(array $attributes, array $options = []): GamResult { return $this->route(__FUNCTION__, [$attributes], $options); }
    public function createCustomTargetingKey(array $attributes, array $options = []): GamResult { return $this->route(__FUNCTION__, [$attributes], $options); }
    public function createCustomTargetingValue(array $attributes, array $options = []): GamResult { return $this->route(__FUNCTION__, [$attributes], $options); }
    public function createOrder(array $attributes, array $options = []): GamResult { return $this->route(__FUNCTION__, [$attributes], $options); }
    public function updateOrder(array $attributes, array $options = []): GamResult { return $this->route(__FUNCTION__, [$attributes], $options); }
    public function createLineItem(array $attributes, array $options = []): GamResult { return $this->route(__FUNCTION__, [$attributes], $options); }
    public function updateLineItem(array $attributes, array $options = []): GamResult { return $this->route(__FUNCTION__, [$attributes], $options); }
    public function createCreative(array $attributes, array $options = []): GamResult { return $this->route(__FUNCTION__, [$attributes], $options); }
    public function associateCreative(array $attributes, array $options = []): GamResult { return $this->route(__FUNCTION__, [$attributes], $options); }
    public function pauseLineItem(array $filterStatement, array $options = []): GamResult { return $this->route(__FUNCTION__, [$filterStatement], $options); }
    public function activateLineItem(array $filterStatement, array $options = []): GamResult { return $this->route(__FUNCTION__, [$filterStatement], $options); }
    public function resumeLineItem(array $filterStatement, array $options = []): GamResult { return $this->route(__FUNCTION__, [$filterStatement], $options); }
    public function archiveObject(array $attributes, array $options = []): GamResult { return $this->route(__FUNCTION__, [$attributes], $options, $attributes); }
    public function runReport(array $reportQuery, array $options = []): GamResult { return $this->route(__FUNCTION__, [$reportQuery], $options); }
    public function getObjectByRemoteId(string $service, string $remoteId, array $options = []): GamResult { return $this->route(__FUNCTION__, [$service, $remoteId], $options, ['service' => $service]); }

    private function route(string $operation, array $arguments, array $options, array $context = []): GamResult
    {
        $connector = $this->capabilities->transportFor($operation, $context) === GamCapabilityRegistry::REST
            ? $this->rest
            : $this->soap;

        return $connector->{$operation}(...array_merge($arguments, [$options]));
    }
}
