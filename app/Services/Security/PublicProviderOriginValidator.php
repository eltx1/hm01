<?php

namespace App\Services\Security;

final class PublicProviderOriginValidator
{
    /**
     * Return a canonical public HTTPS origin or null when the URL is unsafe.
     *
     * Provider origins are publisher-delivered code, so validation is deliberately
     * fail-closed: credentials, local/reserved names, control-plane hosts, DNS
     * failures, and any A/AAAA answer in a private/reserved range are rejected.
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
            return $this->isPublicIp($host);
        }

        if (! str_contains($host, '.')
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return false;
        }

        $addresses = array_values(array_unique($this->resolveAddresses($host)));
        if ($addresses === []) {
            return false;
        }

        return collect($addresses)->every(fn (string $address): bool => $this->isPublicIp($address));
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
        // must still pass the public-address check.
        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            $addresses = array_merge($addresses, $ipv4);
        }

        return array_values(array_filter(array_unique($addresses), 'is_string'));
    }

    private function isPublicIp(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
