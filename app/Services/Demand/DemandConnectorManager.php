<?php

namespace App\Services\Demand;

use App\Models\DemandAccount;
use App\Services\Demand\Contracts\DemandConnectorInterface;
use App\Services\Operations\PlatformControlService;
use RuntimeException;

final class DemandConnectorManager
{
    public function __construct(private readonly DemandSecretResolver $secrets, private readonly PlatformControlService $controls) {}

    public function for(DemandAccount $account): DemandConnectorInterface
    {
        if (! $this->controls->enabled('native_enabled')) {
            throw new RuntimeException('Native and alternative demand connectors are disabled by the global production kill switch.');
        }
        $account->loadMissing(['network', 'credentials']);
        $class = (string) $account->network->connector_class;
        if ($class === '' || ! is_a($class, DemandConnectorInterface::class, true)) {
            throw new RuntimeException('The selected demand network connector is not registered correctly.');
        }
        return new $class($account, $this->secrets);
    }
}
