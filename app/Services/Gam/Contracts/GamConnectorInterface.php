<?php

namespace App\Services\Gam\Contracts;

use App\Models\GamConnection;
use App\Services\Gam\Data\GamResult;

interface GamConnectorInterface
{
    public function connection(): GamConnection;

    public function testConnection(array $options = []): GamResult;
    public function getCurrentNetwork(array $options = []): GamResult;
    public function listAccessibleNetworks(array $options = []): GamResult;
    public function getNetworkByCode(string $networkCode, array $options = []): GamResult;
    public function createCompany(array $attributes, array $options = []): GamResult;
    public function updateCompany(array $attributes, array $options = []): GamResult;
    public function createAdUnit(array $attributes, array $options = []): GamResult;
    public function updateAdUnit(array $attributes, array $options = []): GamResult;
    public function createPlacement(array $attributes, array $options = []): GamResult;
    public function createCustomTargetingKey(array $attributes, array $options = []): GamResult;
    public function createCustomTargetingValue(array $attributes, array $options = []): GamResult;
    public function createOrder(array $attributes, array $options = []): GamResult;
    public function updateOrder(array $attributes, array $options = []): GamResult;
    public function createLineItem(array $attributes, array $options = []): GamResult;
    public function updateLineItem(array $attributes, array $options = []): GamResult;
    public function createCreative(array $attributes, array $options = []): GamResult;
    public function associateCreative(array $attributes, array $options = []): GamResult;
    public function pauseLineItem(array $filterStatement, array $options = []): GamResult;
    public function activateLineItem(array $filterStatement, array $options = []): GamResult;
    public function archiveObject(array $attributes, array $options = []): GamResult;
    public function runReport(array $reportQuery, array $options = []): GamResult;
    public function getObjectByRemoteId(string $service, string $remoteId, array $options = []): GamResult;
}
