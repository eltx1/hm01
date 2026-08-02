<?php

namespace Tests\Fakes;

use App\Models\GamConnection;
use App\Services\Gam\Contracts\GamSoapTransportInterface;

final class FakeGamSoapTransport implements GamSoapTransportInterface
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    private int $sequence = 8000;

    public function call(GamConnection $connection, string $service, string $method, array $payload = []): array
    {
        $this->calls[] = compact('service', 'method', 'payload');

        return str_starts_with($method, 'create')
            ? ['id' => (string) ++$this->sequence, 'status' => 'ACTIVE']
            : ['numChanges' => 1];
    }
}
