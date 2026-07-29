<?php

namespace App\Services\Demand;

final class ConfiguredDemandConnector extends AbstractDemandConnector
{
    protected function code(): string
    {
        return $this->selectedAccount->network->code->value;
    }
}
