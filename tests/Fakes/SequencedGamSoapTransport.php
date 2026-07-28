<?php

namespace Tests\Fakes;

use App\Models\GamConnection;
use App\Services\Gam\Contracts\GamSoapTransportInterface;

final class SequencedGamSoapTransport implements GamSoapTransportInterface
{
    public array $calls = [];
    private int $sequence = 10000;

    public function call(GamConnection $connection, string $service, string $method, array $payload = []): array
    {
        $id = (string) ++$this->sequence;
        $this->calls[] = compact('service', 'method', 'payload', 'id');

        return match ($service.'.'.$method) {
            'NetworkService.getCurrentNetwork' => [
                'networkCode' => $connection->network_code,
                'displayName' => $connection->name,
                'currencyCode' => 'USD',
                'timeZone' => 'America/New_York',
            ],
            'NetworkService.getAllNetworks' => [[
                'networkCode' => $connection->network_code,
                'displayName' => $connection->name,
                'currencyCode' => 'USD',
                'timeZone' => 'America/New_York',
            ]],
            default => ['rval' => [['id' => $id, 'status' => 'ACTIVE']]],
        };
    }
}
