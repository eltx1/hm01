<?php

namespace App\Services\SupplyChain;

use InvalidArgumentException;

final class DomainNormalizer
{
    public function normalize(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $candidate = str_contains($value, '://') ? $value : 'https://'.$value;
        $parts = parse_url($candidate);
        if (! is_array($parts) || empty($parts['host'])) {
            throw new InvalidArgumentException('A valid registrable domain is required.');
        }
        if (! in_array(strtolower((string) ($parts['scheme'] ?? 'https')), ['http', 'https'], true)) {
            throw new InvalidArgumentException('Only a bare domain or an HTTP(S) domain URL can be normalized.');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || filled($parts['query'] ?? null) || filled($parts['fragment'] ?? null)
            || (isset($parts['path']) && ! in_array($parts['path'], ['', '/'], true))) {
            throw new InvalidArgumentException('Supply-chain domains must not contain credentials, ports, paths, queries, or fragments.');
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                throw new InvalidArgumentException('The internationalized domain could not be normalized.');
            }
            $host = strtolower($ascii);
        }

        if (strlen($host) > 253 || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new InvalidArgumentException('Supply-chain identity requires a domain name, not an IP address.');
        }

        $labels = explode('.', $host);
        if (count($labels) < 2 || collect($labels)->contains(
            fn (string $label): bool => $label === ''
                || strlen($label) > 63
                || preg_match('/^(?!-)[a-z0-9-]+(?<!-)$/', $label) !== 1,
        )) {
            throw new InvalidArgumentException('A valid registrable domain is required.');
        }
        $topLevel = end($labels);
        if (preg_match('/^(?:[a-z]{2,63}|xn--[a-z0-9-]{2,59})$/', $topLevel) !== 1) {
            throw new InvalidArgumentException('A valid public domain suffix is required.');
        }

        return $host;
    }

    public function same(?string $left, ?string $right): bool
    {
        return $this->normalize($left) === $this->normalize($right);
    }
}
