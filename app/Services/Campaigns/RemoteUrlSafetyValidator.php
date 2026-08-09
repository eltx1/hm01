<?php

namespace App\Services\Campaigns;

use App\Services\Network\Contracts\DnsResolver;
use Illuminate\Validation\ValidationException;

final class RemoteUrlSafetyValidator
{
    public function __construct(private readonly DnsResolver $dns) {}

    public function assertPublicHttpUrl(string $url, string $field): void
    {
        $this->publicAddresses($url, $field);
    }

    /** @return list<string> */
    public function publicAddresses(string $url, string $field): array
    {
        $parts = parse_url($url);
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if (! in_array($scheme, ['http', 'https'], true)
            || $host === '' || isset($parts['user']) || isset($parts['pass'])
            || ! in_array($port, [80, 443], true)
            || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw ValidationException::withMessages([$field => 'The remote URL is not safe for server-side validation.']);
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->dns->addresses($host);
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
}
