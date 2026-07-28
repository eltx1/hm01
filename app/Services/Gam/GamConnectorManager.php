<?php

namespace App\Services\Gam;

use App\Models\GamConnection;
use App\Services\Gam\Contracts\GamConnectorInterface;
use App\Services\Gam\Contracts\GamSoapTransportInterface;

final class GamConnectorManager
{
    public function __construct(
        private readonly GamSoapTransportInterface $soapTransport,
        private readonly GamOperationExecutor $executor,
    ) {
    }

    public function for(GamConnection $connection): GamConnectorInterface
    {
        return match (strtoupper($connection->driver)) {
            'MOCK' => new GamMockConnector($connection),
            'REST' => new GamRestConnectorPlaceholder($connection),
            default => new GamSoapConnector($connection, $this->soapTransport, $this->executor),
        };
    }
}
