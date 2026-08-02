<?php

namespace App\Services\Gam\Contracts;

use App\Models\GamConnection;

interface GamSoapTransportInterface
{
    /** @return array<string, mixed> */
    public function call(GamConnection $connection, string $service, string $method, array $payload = []): array;
}
