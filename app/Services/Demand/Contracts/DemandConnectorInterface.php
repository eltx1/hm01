<?php

namespace App\Services\Demand\Contracts;

use App\Models\DemandAccount;
use App\Models\DemandPlacement;
use App\Models\DemandSite;
use App\Services\Demand\Data\DemandResult;
use Carbon\CarbonInterface;

interface DemandConnectorInterface
{
    public function account(): DemandAccount;

    public function testConnection(array $options = []): DemandResult;

    /** Return a list of configuration errors. An empty list is valid. */
    public function validateConfiguration(array $configuration = []): array;

    public function createSite(DemandSite $site, array $options = []): DemandResult;
    public function getSiteStatus(DemandSite $site, array $options = []): DemandResult;
    public function createPlacement(DemandPlacement $placement, array $options = []): DemandResult;
    public function getPlacementCode(DemandPlacement $placement, array $options = []): DemandResult;

    /**
     * Parse a provider-issued public tag into a reviewable structured recipe.
     * The supplied markup is never executed.
     *
     * @return array<string, mixed>
     */
    public function parseDirectTag(string $tag): array;

    /** @return array<string, mixed> */
    public function generateDirectTag(DemandPlacement $placement): array;
    public function generateGamCreative(DemandPlacement $placement): array;
    public function getAdsTxtRecords(?DemandSite $site = null): array;
    public function runReport(CarbonInterface $from, CarbonInterface $to, array $options = []): DemandResult;
    public function pausePlacement(DemandPlacement $placement, array $options = []): DemandResult;
    public function activatePlacement(DemandPlacement $placement, array $options = []): DemandResult;
}
