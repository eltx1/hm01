<?php

namespace App\Services\Campaigns;

use Illuminate\Validation\ValidationException;

final class RemoteUrlSafetyValidator
{
    public function assertPublicHttpUrl(string $url, string $field): void
    {
        $this->publicAddresses($url, $field);
    }

    /** @return list<string> */
    public function publicAddresses(string $url, string $field): array
    {
        $parts = parse_url($url);
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || $host === '' || isset($parts['user']) || isset($parts['pass'])
            || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw ValidationException::withMessages([$field => 'The remote URL is not safe for server-side validation.']);
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->addresses($host);
        if ($addresses === []) {
            throw ValidationException::withMessages([$field => 'The remote hostname could not be resolved safely.']);
        }
        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw ValidationException::withMessages([$field => 'The remote URL resolves to a private or reserved network.']);
            }
        }

        return $addresses;
    }

    private function addresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        return array_values(array_unique(array_filter(array_map(fn ($record) => $record['ip'] ?? $record['ipv6'] ?? null, $records))));
    }
}
