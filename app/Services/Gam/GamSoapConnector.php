<?php

namespace App\Services\Gam;

use App\Models\GamConnection;
use App\Services\Gam\Contracts\GamConnectorInterface;
use App\Services\Gam\Contracts\GamSoapTransportInterface;
use App\Services\Gam\Data\GamResult;
use InvalidArgumentException;

final class GamSoapConnector implements GamConnectorInterface
{
    public function __construct(
        private readonly GamConnection $gamConnection,
        private readonly GamSoapTransportInterface $transport,
        private readonly GamOperationExecutor $executor,
    ) {
    }

    public function connection(): GamConnection
    {
        return $this->gamConnection;
    }

    public function testConnection(array $options = []): GamResult
    {
        return $this->read('testConnection', 'NetworkService', 'getCurrentNetwork', [], $options);
    }

    public function getCurrentNetwork(array $options = []): GamResult
    {
        return $this->read('getCurrentNetwork', 'NetworkService', 'getCurrentNetwork', [], $options);
    }

    public function listAccessibleNetworks(array $options = []): GamResult
    {
        return $this->read('listAccessibleNetworks', 'NetworkService', 'getAllNetworks', [], $options);
    }

    public function getNetworkByCode(string $networkCode, array $options = []): GamResult
    {
        $result = $this->listAccessibleNetworks($options);
        if (! $result->success || $result->dryRun) {
            return $result;
        }

        $networks = $this->extractList($result->data);
        $network = collect($networks)->first(fn ($item) => (string) data_get($item, 'networkCode') === $networkCode);

        return $network
            ? GamResult::success(is_array($network) ? $network : (array) $network, $result->operationId)
            : GamResult::failure('VALIDATION', 'NETWORK_NOT_FOUND', "Network {$networkCode} is not accessible.", $result->operationId);
    }

    public function createCompany(array $attributes, array $options = []): GamResult
    {
        return $this->write('createCompany', 'CompanyService', 'createCompanies', ['companies' => [$attributes]], $options);
    }

    public function updateCompany(array $attributes, array $options = []): GamResult
    {
        return $this->write('updateCompany', 'CompanyService', 'updateCompanies', ['companies' => [$attributes]], $options);
    }

    public function createAdUnit(array $attributes, array $options = []): GamResult
    {
        return $this->write('createAdUnit', 'InventoryService', 'createAdUnits', ['adUnits' => [$attributes]], $options);
    }

    public function updateAdUnit(array $attributes, array $options = []): GamResult
    {
        return $this->write('updateAdUnit', 'InventoryService', 'updateAdUnits', ['adUnits' => [$attributes]], $options);
    }

    public function createPlacement(array $attributes, array $options = []): GamResult
    {
        return $this->write('createPlacement', 'PlacementService', 'createPlacements', ['placements' => [$attributes]], $options);
    }

    public function createCustomTargetingKey(array $attributes, array $options = []): GamResult
    {
        return $this->write('createCustomTargetingKey', 'CustomTargetingService', 'createCustomTargetingKeys', ['keys' => [$attributes]], $options);
    }

    public function createCustomTargetingValue(array $attributes, array $options = []): GamResult
    {
        return $this->write('createCustomTargetingValue', 'CustomTargetingService', 'createCustomTargetingValues', ['values' => [$attributes]], $options);
    }

    public function createOrder(array $attributes, array $options = []): GamResult
    {
        return $this->write('createOrder', 'OrderService', 'createOrders', ['orders' => [$attributes]], $options);
    }

    public function updateOrder(array $attributes, array $options = []): GamResult
    {
        return $this->write('updateOrder', 'OrderService', 'updateOrders', ['orders' => [$attributes]], $options);
    }

    public function createLineItem(array $attributes, array $options = []): GamResult
    {
        return $this->write('createLineItem', 'LineItemService', 'createLineItems', ['lineItems' => [$attributes]], $options);
    }

    public function updateLineItem(array $attributes, array $options = []): GamResult
    {
        return $this->write('updateLineItem', 'LineItemService', 'updateLineItems', ['lineItems' => [$attributes]], $options);
    }

    public function createCreative(array $attributes, array $options = []): GamResult
    {
        return $this->write('createCreative', 'CreativeService', 'createCreatives', ['creatives' => [$attributes]], $options);
    }

    public function associateCreative(array $attributes, array $options = []): GamResult
    {
        return $this->write(
            'associateCreative',
            'LineItemCreativeAssociationService',
            'createLineItemCreativeAssociations',
            ['lineItemCreativeAssociations' => [$attributes]],
            $options,
        );
    }

    public function pauseLineItem(array $filterStatement, array $options = []): GamResult
    {
        return $this->write('pauseLineItem', 'LineItemService', 'performLineItemAction', [
            'lineItemAction' => ['__type' => 'PauseLineItems'],
            'filterStatement' => $filterStatement,
        ], $options);
    }

    public function activateLineItem(array $filterStatement, array $options = []): GamResult
    {
        return $this->write('activateLineItem', 'LineItemService', 'performLineItemAction', [
            'lineItemAction' => ['__type' => 'ResumeLineItems'],
            'filterStatement' => $filterStatement,
        ], $options);
    }

    public function archiveObject(array $attributes, array $options = []): GamResult
    {
        foreach (['service', 'method', 'action_type', 'filter_statement'] as $required) {
            if (! array_key_exists($required, $attributes)) {
                throw new InvalidArgumentException("archiveObject requires {$required}.");
            }
        }

        return $this->write('archiveObject', (string) $attributes['service'], (string) $attributes['method'], [
            'action' => ['__type' => (string) $attributes['action_type']],
            'filterStatement' => (array) $attributes['filter_statement'],
        ], $options);
    }

    public function runReport(array $reportQuery, array $options = []): GamResult
    {
        return $this->write('runReport', 'ReportService', 'runReportJob', [
            'reportJob' => ['reportQuery' => $reportQuery],
        ], $options);
    }

    public function getObjectByRemoteId(string $service, string $remoteId, array $options = []): GamResult
    {
        $method = (string) ($options['method'] ?? 'get'.str_replace('Service', '', $service).'ByStatement');
        $idField = (string) ($options['id_field'] ?? 'id');
        $payload = [
            'filterStatement' => [
                'query' => "WHERE {$idField} = :remoteId",
                'values' => [[
                    'key' => 'remoteId',
                    'value' => ['__type' => 'NumberValue', 'value' => $remoteId],
                ]],
            ],
        ];

        return $this->read('getObjectByRemoteId', $service, $method, $payload, $options);
    }

    private function read(string $operation, string $service, string $method, array $payload, array $options): GamResult
    {
        return $this->executor->execute(
            $this->gamConnection,
            $operation,
            $service,
            $method,
            $payload,
            fn () => $this->transport->call($this->gamConnection, $service, $method, $payload),
            array_merge($options, ['write' => false, 'dry_run' => false]),
        );
    }

    private function write(string $operation, string $service, string $method, array $payload, array $options): GamResult
    {
        return $this->executor->execute(
            $this->gamConnection,
            $operation,
            $service,
            $method,
            $payload,
            fn () => $this->transport->call($this->gamConnection, $service, $method, $payload),
            array_merge(['write' => true], $options),
        );
    }

    private function extractList(array $data): array
    {
        foreach (['rval', 'results', 'networks', 'value'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return array_is_list($data[$key]) ? $data[$key] : [$data[$key]];
            }
        }

        return array_is_list($data) ? $data : [$data];
    }
}
