<?php

namespace App\Services\Gam;

use App\Models\GamConnection;
use App\Services\Gam\Contracts\GamConnectorInterface;
use App\Services\Gam\Contracts\GamSoapTransportInterface;
use App\Services\Gam\Data\GamResult;
use InvalidArgumentException;

/** Temporary write fallback for capabilities that GAM REST v1 has not published. */
final class GamSoapConnector implements GamConnectorInterface
{
    public function __construct(
        private readonly GamConnection $gamConnection,
        private readonly GamSoapTransportInterface $transport,
        private readonly GamOperationExecutor $executor,
    ) {}

    public function connection(): GamConnection { return $this->gamConnection; }
    public function testConnection(array $options = []): GamResult { return $this->read(__FUNCTION__, 'NetworkService', 'getCurrentNetwork', [], $options); }
    public function getCurrentNetwork(array $options = []): GamResult { return $this->read(__FUNCTION__, 'NetworkService', 'getCurrentNetwork', [], $options); }
    public function listAccessibleNetworks(array $options = []): GamResult { return $this->read(__FUNCTION__, 'NetworkService', 'getAllNetworks', [], $options); }

    public function getNetworkByCode(string $networkCode, array $options = []): GamResult
    {
        $result = $this->listAccessibleNetworks($options);
        if (! $result->success) return $result;
        $network = collect($result->data)->first(fn (mixed $item) => (string) data_get($item, 'networkCode') === $networkCode);

        return is_array($network)
            ? GamResult::success($network, $result->operationId)
            : GamResult::failure('VALIDATION', 'NETWORK_NOT_FOUND', "Network {$networkCode} is not accessible.", $result->operationId);
    }

    public function createCompany(array $attributes, array $options = []): GamResult { return $this->write(__FUNCTION__, 'CompanyService', 'createCompanies', ['companies' => [$attributes]], $options); }
    public function updateCompany(array $attributes, array $options = []): GamResult { return $this->write(__FUNCTION__, 'CompanyService', 'updateCompanies', ['companies' => [$attributes]], $options); }
    public function createAdUnit(array $attributes, array $options = []): GamResult { return $this->write(__FUNCTION__, 'InventoryService', 'createAdUnits', ['adUnits' => [$attributes]], $options); }
    public function updateAdUnit(array $attributes, array $options = []): GamResult { return $this->write(__FUNCTION__, 'InventoryService', 'updateAdUnits', ['adUnits' => [$attributes]], $options); }
    public function createPlacement(array $attributes, array $options = []): GamResult { return $this->write(__FUNCTION__, 'PlacementService', 'createPlacements', ['placements' => [$attributes]], $options); }
    public function createCustomTargetingKey(array $attributes, array $options = []): GamResult { return $this->write(__FUNCTION__, 'CustomTargetingService', 'createCustomTargetingKeys', ['keys' => [$attributes]], $options); }
    public function createCustomTargetingValue(array $attributes, array $options = []): GamResult { return $this->write(__FUNCTION__, 'CustomTargetingService', 'createCustomTargetingValues', ['values' => [$attributes]], $options); }
    public function createOrder(array $attributes, array $options = []): GamResult { return $this->write(__FUNCTION__, 'OrderService', 'createOrders', ['orders' => [$attributes]], $options); }
    public function updateOrder(array $attributes, array $options = []): GamResult { return $this->write(__FUNCTION__, 'OrderService', 'updateOrders', ['orders' => [$attributes]], $options); }
    public function createLineItem(array $attributes, array $options = []): GamResult { return $this->write(__FUNCTION__, 'LineItemService', 'createLineItems', ['lineItems' => [$attributes]], $options); }
    public function updateLineItem(array $attributes, array $options = []): GamResult { return $this->write(__FUNCTION__, 'LineItemService', 'updateLineItems', ['lineItems' => [$attributes]], $options); }
    public function createCreative(array $attributes, array $options = []): GamResult { return $this->write(__FUNCTION__, 'CreativeService', 'createCreatives', ['creatives' => [$attributes]], $options); }
    public function associateCreative(array $attributes, array $options = []): GamResult { return $this->write(__FUNCTION__, 'LineItemCreativeAssociationService', 'createLineItemCreativeAssociations', ['lineItemCreativeAssociations' => [$attributes]], $options); }

    public function pauseLineItem(array $filterStatement, array $options = []): GamResult
    {
        return $this->write(__FUNCTION__, 'LineItemService', 'performLineItemAction', [
            'lineItemAction' => ['__type' => 'PauseLineItems'], 'filterStatement' => $filterStatement,
        ], $options);
    }

    public function activateLineItem(array $filterStatement, array $options = []): GamResult
    {
        return $this->write(__FUNCTION__, 'LineItemService', 'performLineItemAction', [
            'lineItemAction' => ['__type' => 'ActivateLineItems'], 'filterStatement' => $filterStatement,
        ], $options);
    }

    public function resumeLineItem(array $filterStatement, array $options = []): GamResult
    {
        return $this->write(__FUNCTION__, 'LineItemService', 'performLineItemAction', [
            'lineItemAction' => ['__type' => 'ResumeLineItems'], 'filterStatement' => $filterStatement,
        ], $options);
    }

    public function archiveObject(array $attributes, array $options = []): GamResult
    {
        foreach (['service', 'method', 'action_type', 'filter_statement'] as $required) {
            if (! array_key_exists($required, $attributes)) throw new InvalidArgumentException("archiveObject requires {$required}.");
        }

        $service = (string) $attributes['service'];
        $actionParameter = match ($service) {
            'CreativeService' => 'creativeAction',
            'LineItemCreativeAssociationService' => 'lineItemCreativeAssociationAction',
            'LineItemService' => 'lineItemAction',
            default => 'action',
        };

        return $this->write(__FUNCTION__, $service, (string) $attributes['method'], [
            $actionParameter => ['__type' => (string) $attributes['action_type']],
            'filterStatement' => (array) $attributes['filter_statement'],
        ], $options);
    }

    public function runReport(array $reportQuery, array $options = []): GamResult
    {
        return $this->write(__FUNCTION__, 'ReportService', 'runReportJob', ['reportJob' => ['reportQuery' => $reportQuery]], $options);
    }

    public function getObjectByRemoteId(string $service, string $remoteId, array $options = []): GamResult
    {
        $method = (string) ($options['method'] ?? 'get'.str_replace('Service', '', $service).'ByStatement');
        $idField = (string) ($options['id_field'] ?? 'id');

        return $this->read(__FUNCTION__, $service, $method, ['filterStatement' => [
            'query' => "WHERE {$idField} = :remoteId",
            'values' => [['key' => 'remoteId', 'value' => ['__type' => 'NumberValue', 'value' => $remoteId]]],
        ]], $options);
    }

    private function read(string $operation, string $service, string $method, array $payload, array $options): GamResult
    {
        return $this->executor->execute($this->gamConnection, $operation, 'SOAP:'.$service, $method, $payload,
            fn () => $this->transport->call($this->gamConnection, $service, $method, $payload),
            array_merge($options, ['write' => false, 'dry_run' => false]));
    }

    private function write(string $operation, string $service, string $method, array $payload, array $options): GamResult
    {
        return $this->executor->execute($this->gamConnection, $operation, 'SOAP:'.$service, $method, $payload,
            fn () => $this->transport->call($this->gamConnection, $service, $method, $payload),
            array_merge(['write' => true], $options));
    }
}
