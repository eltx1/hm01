<?php

namespace App\Services\Gam;

use App\Models\GamConnection;
use App\Services\Gam\Contracts\GamConnectorInterface;
use App\Services\Gam\Contracts\GamSoapTransportInterface;

final class GamConnectorManager
{
    public function __construct(
        private readonly GamOperationExecutor $executor,
        private readonly GamOAuthTokenProvider $tokens,
        private readonly GamSoapTransportInterface $soapTransport,
        private readonly GamCapabilityRegistry $capabilities,
    ) {
    }

    public function for(GamConnection $connection): GamConnectorInterface
    {
        if (strtoupper($connection->driver) === 'MOCK' && app()->environment('testing')) {
            return new GamMockConnector($connection);
        }

        $rest = new GamRestConnector($connection, $this->tokens, $this->executor);
        $soap = new GamSoapConnector($connection, $this->soapTransport, $this->executor);

        return new GamHybridConnector($rest, $soap, $this->capabilities);
    }
}
