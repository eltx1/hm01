<?php

namespace App\Services\Sites;

class DnsResolver
{
    /** @return list<string> */
    public function addresses(string $domain): array
    {
        $records = array_merge(dns_get_record($domain, DNS_A) ?: [], dns_get_record($domain, DNS_AAAA) ?: []);

        return array_values(array_filter(array_map(fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null, $records)));
    }

    /** @return list<string> */
    public function textRecords(string $domain): array
    {
        $records = dns_get_record($domain, DNS_TXT) ?: [];

        return array_values(array_filter(array_map(fn (array $record) => $record['txt'] ?? null, $records)));
    }
}
