<?php

namespace App\Services\Demand;

use App\Models\DemandAccount;
use App\Services\Demand\Contracts\DemandConnectorInterface;
use RuntimeException;

final class DemandConnectorManager
{
    public function __construct(private readonly DemandSecretResolver $secrets)
    {
    }

    public function for(DemandAccount $account): DemandConnectorInterface
    {
        $account->loadMissing(['network', 'credentials']);
        $class = (string) $account->network->connector_class;

        if ($class === '' || ! is_a($class, DemandConnectorInterface::class, true)) {
            throw new RuntimeException('The selected demand network connector is not registered correctly.');
        }

        return new $class($account, $this->secrets);
    }
}
