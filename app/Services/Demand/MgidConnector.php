<?php

namespace App\Services\Demand;

use App\Models\DemandPlacement;

final class MgidConnector extends AbstractDemandConnector
{
    protected function code(): string
    {
        return 'MGID';
    }

    public function validateConfiguration(array $configuration = []): array
    {
        return array_values(array_unique(array_merge(
            parent::validateConfiguration($configuration),
            $this->requiredAccountIdentifier(),
        )));
    }

    public function generateDirectTag(DemandPlacement $placement): array
    {
        $tag = parent::generateDirectTag($placement);
        $tag['containerClass'] = $tag['containerClass'] === 'hm-native-container'
            ? 'mgbox'
            : $tag['containerClass'];
        $tag['attributes'] = ['data-type' => '_mgwidget'] + $tag['attributes'];

        return $tag;
    }
}
