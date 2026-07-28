<?php

namespace App\Services\Gam;

final class GamPayloadSanitizer
{
    private const SENSITIVE_FRAGMENTS = [
        'password', 'secret', 'token', 'authorization', 'credential', 'private_key',
        'refresh_token', 'client_secret', 'json_key', 'access_token', 'assertion',
    ];

    public function sanitize(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $clean = [];
            foreach ($value as $itemKey => $itemValue) {
                $clean[$itemKey] = $this->sanitize($itemValue, (string) $itemKey);
            }

            return $clean;
        }

        if (is_object($value)) {
            return $this->sanitize(get_object_vars($value), $key);
        }

        if (is_string($value)) {
            if (str_contains($value, '-----BEGIN PRIVATE KEY-----') || preg_match('/Bearer\s+[A-Za-z0-9._~-]+/i', $value)) {
                return '[REDACTED]';
            }

            return mb_substr($value, 0, 10000);
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
