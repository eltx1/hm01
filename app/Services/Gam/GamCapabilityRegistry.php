<?php

namespace App\Services\Gam;

use InvalidArgumentException;

final class GamCapabilityRegistry
{
    public const REST = 'REST';

    public const SOAP = 'SOAP_FALLBACK';

    /**
     * REST v1 operations that Horus has implemented and verified.
     * Promotion of a write from SOAP requires an implemented REST method,
     * integration coverage, and an explicit change to this registry. It never
     * requires a connection migration or a publisher-side release.
     *
     * @var list<string>
     */
    private const REST_OPERATIONS = [
        'testConnection', 'getCurrentNetwork', 'listAccessibleNetworks', 'getNetworkByCode',
        'createAdUnit', 'updateAdUnit', 'createPlacement', 'createCustomTargetingKey',
        'createCustomTargetingValue', 'createOrder', 'updateOrder', 'runReport',
    ];

    /** @var list<string> */
    private const SOAP_FALLBACK_OPERATIONS = [
        'createCompany', 'updateCompany', 'createLineItem', 'updateLineItem',
        'createCreative', 'associateCreative', 'pauseLineItem', 'activateLineItem', 'resumeLineItem',
    ];

    public function transportFor(string $operation, array $context = []): string
    {
        if ($operation === 'archiveObject') {
            return isset($context['service']) ? self::SOAP : self::REST;
        }

        if ($operation === 'getObjectByRemoteId') {
            return $this->restReadableService((string) ($context['service'] ?? '')) ? self::REST : self::SOAP;
        }

        if (in_array($operation, self::REST_OPERATIONS, true)) {
            return self::REST;
        }

        if (in_array($operation, self::SOAP_FALLBACK_OPERATIONS, true)) {
            return self::SOAP;
        }

        throw new InvalidArgumentException("No GAM capability route is registered for {$operation}.");
    }

    /** @return array<string, string> */
    public function matrix(): array
    {
        $matrix = [];
        foreach (self::REST_OPERATIONS as $operation) $matrix[$operation] = self::REST;
        foreach (self::SOAP_FALLBACK_OPERATIONS as $operation) $matrix[$operation] = self::SOAP;
        $matrix['archiveObject'] = 'CONTEXTUAL';
        $matrix['getObjectByRemoteId'] = 'CONTEXTUAL';
        ksort($matrix);

        return $matrix;
    }

    private function restReadableService(string $service): bool
    {
        return in_array($service, [
            'NetworkService', 'CompanyService', 'InventoryService', 'PlacementService', 'CustomTargetingService',
            'OrderService', 'LineItemService', 'ReportService', 'networks', 'adUnits',
            'companies', 'placements', 'customTargetingKeys', 'customTargetingValues', 'orders',
            'lineItems', 'reports',
        ], true);
    }
}
