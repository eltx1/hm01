<?php

namespace App\Services\Sites;

use Illuminate\Validation\ValidationException;

class DomainSafetyValidator
{
    public function __construct(private readonly DnsResolver $dns) {}

    /** @return list<string> */
    public function assertSafe(string $domain): array
    {
        if (! filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || str_ends_with($domain, '.local') || $domain === 'localhost') {
            throw ValidationException::withMessages(['domain' => 'Enter a valid public domain name.']);
        }

        $addresses = $this->dns->addresses($domain);

        if ($addresses === [] || collect($addresses)->contains(fn (string $ip) => ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
            throw ValidationException::withMessages(['domain' => 'The domain must resolve only to public internet addresses.']);
        }

        return array_values($addresses);
    }
}
