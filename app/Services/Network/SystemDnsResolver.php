<?php

namespace App\Services\Network;

use App\Services\Network\Contracts\DnsResolver;

final class SystemDnsResolver implements DnsResolver
{
    public function addresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_unique(array_filter(array_map(
            fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        ))));
    }
}
