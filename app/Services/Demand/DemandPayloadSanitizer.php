<?php

namespace App\Services\Demand;

final class DemandPayloadSanitizer
{
    private const SENSITIVE = [
        'secret', 'token', 'password', 'credential', 'reference', 'api_key',
        'apikey', 'authorization', 'private_key', 'client_secret',
    ];

    public function sanitize(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->sensitive($key)) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $childKey => $childValue) {
                $sanitized[$childKey] = $this->sanitize($childValue, (string) $childKey);
            }

            return $sanitized;
        }

        if (is_object($value)) {
            return $this->sanitize((array) $value, $key);
        }

        if (is_string($value) && (str_starts_with($value, 'env:') || str_starts_with($value, 'file:'))) {
            return '[CREDENTIAL_REFERENCE]';
        }

        return $value;
    }

    private function sensitive(string $key): bool
    {
        $key = strtolower($key);

        return collect(self::SENSITIVE)->contains(fn (string $fragment) => str_contains($key, $fragment));
    }
}
