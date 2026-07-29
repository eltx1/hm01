<?php

namespace App\Services\Demand;

final class TaboolaConnector extends AbstractDemandConnector
{
    protected function code(): string
    {
        return 'TABOOLA';
    }

    public function validateConfiguration(array $configuration = []): array
    {
        return array_values(array_unique(array_merge(
            parent::validateConfiguration($configuration),
            $this->requiredAccountIdentifier(),
        )));
    }
}
