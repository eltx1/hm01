<?php

namespace App\Services\Gam;

use App\Models\GamConnection;
use App\Services\Gam\Contracts\GamConnectorInterface;

final class GamConnectorManager
{
    public function __construct(
        private readonly GamOperationExecutor $executor,
        private readonly GamOAuthTokenProvider $tokens,
    ) {
    }

    public function for(GamConnection $connection): GamConnectorInterface
    {
        if (strtoupper($connection->driver) === 'MOCK' && app()->environment('testing')) {
            return new GamMockConnector($connection);
        }

        return new GamRestConnector($connection, $this->tokens, $this->executor);
    }
}
