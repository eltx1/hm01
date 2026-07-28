<?php

namespace Tests\Fakes;

use App\Models\GamConnection;
use App\Services\Gam\Contracts\GamSoapTransportInterface;
use App\Services\Gam\Exceptions\GamTransportException;

final class FakeGamSoapTransport implements GamSoapTransportInterface
{
    public array $calls = [];
    public array $responses = [];
    public ?GamTransportException $exception = null;

    public function call(GamConnection $connection, string $service, string $method, array $payload = []): array
    {
        $this->calls[] = [
            'connection_id' => $connection->id,
            'service' => $service,
            'method' => $method,
            'payload' => $payload,
        ];

        if ($this->exception) {
            throw $this->exception;
        }

        $key = $service.'.'.$method;

        return $this->responses[$key] ?? match ($key) {
            'NetworkService.getCurrentNetwork' => [
                'networkCode' => $connection->network_code ?: '123456789',
                'displayName' => 'Horus Media GAM',
                'currencyCode' => 'USD',
                'timeZone' => 'Africa/Cairo',
            ],
            'NetworkService.getAllNetworks' => [[
                'networkCode' => $connection->network_code ?: '123456789',
                'displayName' => 'Horus Media GAM',
                'currencyCode' => 'USD',
                'timeZone' => 'Africa/Cairo',
            ]],
            default => ['id' => '9001', 'status' => 'ACTIVE'],
        };
    }
}
