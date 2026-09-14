<?php

namespace App\Services\Security;

class PublicProviderOriginValidator
{
    /**
     * Return a canonical public HTTPS origin or null when the URL is unsafe.
     *
     * Provider origins are publisher-delivered code, so validation is deliberately
     * fail-closed: credentials, local/reserved names, control-plane hosts, DNS
     * failures, and any non-global A/AAAA answer are rejected.
     */
    public function canonicalOrigin(string $url): ?string
    {
        $url = trim($url);
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }
        if (! filter_var($url, FILTER_VALIDATE_URL)
            || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null) {
            return null;
        }

        $host = $this->normalizeHost((string) parse_url($url, PHP_URL_HOST));
        if (! $this->isPublicProviderHost($host)) {
            return null;
        }

        $originHost = str_contains($host, ':') ? '['.$host.']' : $host;
        $port = parse_url($url, PHP_URL_PORT);

        return 'https://'.$originHost.($port && (int) $port !== 443 ? ':'.(int) $port : '');
    }

    public function normalizeHost(string $host): string
    {
        return rtrim(strtolower(trim($host, '[]')), '.');
    }

    public function isPublicProviderHost(string $host): bool
    {
        $host = $this->normalizeHost($host);
        if ($host === ''
            || $host === 'localhost'
            || $host === 'localhost.localdomain'
            || $host === 'app.horusmedia.net'
            || str_ends_with($host, '.app.horusmedia.net')) {
            return false;
        }

        foreach (['.localhost', '.local', '.internal', '.home.arpa', '.test', '.invalid', '.example'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isGlobalUnicastIp($host);
        }

        if (! str_contains($host, '.')
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return false;
        }

        $addresses = array_values(array_unique($this->resolveAddresses($host)));
        if ($addresses === []) {
            return false;
        }
        foreach ($addresses as $address) {
            if (! is_string($address) || ! $this->isGlobalUnicastIp($address)) {
                return false;
            }
        }

        return true;
    }

    protected function resolveAddresses(string $host): array
    {
        $addresses = [];
        if (function_exists('dns_get_record')) {
            foreach ([(defined('DNS_A') ? DNS_A : 1), (defined('DNS_AAAA') ? DNS_AAAA : 134217728)] as $type) {
                $records = @dns_get_record($host, $type);
                if (! is_array($records)) {
                    continue;
                }
                foreach ($records as $record) {
                    $address = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
                    if ($address !== '') {
                        $addresses[] = $address;
                    }
                }
            }
        }

        // Some resolvers expose A answers through gethostbynamel more reliably
        // than dns_get_record. Merge rather than replace so every observed answer
        // must still pass the global-unicast check.
        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            $addresses = array_merge($addresses, $ipv4);
        }

        return array_values(array_filter(array_unique($addresses), 'is_string'));
    }

    private function isGlobalUnicastIp(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            // Explicitly deny the complete IPv4 non-global/special-purpose sets
            // that are relevant to browser-side network access. This includes
            // CGNAT and documentation/benchmarking ranges that PHP's generic
            // NO_PRIV/NO_RES flags do not consistently classify as non-public.
            foreach ([
                '0.0.0.0/8',
                '10.0.0.0/8',
                '100.64.0.0/10',
                '127.0.0.0/8',
                '169.254.0.0/16',
                '172.16.0.0/12',
                '192.0.0.0/24',
                '192.0.2.0/24',
                '192.88.99.0/24',
                '192.168.0.0/16',
                '198.18.0.0/15',
                '198.51.100.0/24',
                '203.0.113.0/24',
                '224.0.0.0/4',
                '240.0.0.0/4',
            ] as $cidr) {
                if ($this->ipInCidr($packed, $cidr)) {
                    return false;
                }
            }

            return true;
        }

        if (strlen($packed) !== 16) {
            return false;
        }

        // Public provider endpoints must be IPv6 global unicast. Requiring the
        // IANA global-unicast aggregate excludes unspecified, loopback, mapped,
        // NAT64, discard, ULA, link-local, site-local, and multicast space in one
        // fail-closed rule. Explicit exclusions cover special/documentation
        // prefixes that sit inside 2000::/3.
        if (! $this->ipInCidr($packed, '2000::/3')) {
            return false;
        }
        foreach ([
            '2001::/23',
            '2001:db8::/32',
            '2002::/16',
            '3fff::/20',
        ] as $cidr) {
            if ($this->ipInCidr($packed, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private function ipInCidr(string $packedAddress, string $cidr): bool
    {
        [$network, $prefixLength] = explode('/', $cidr, 2);
        $packedNetwork = @inet_pton($network);
        if ($packedNetwork === false || strlen($packedNetwork) !== strlen($packedAddress)) {
            return false;
        }

        $bits = (int) $prefixLength;
        $maximumBits = strlen($packedAddress) * 8;
        if ($bits < 0 || $bits > $maximumBits) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;
        if ($wholeBytes > 0
            && substr($packedAddress, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;

        return (ord($packedAddress[$wholeBytes]) & $mask) === (ord($packedNetwork[$wholeBytes]) & $mask);
    }
}
