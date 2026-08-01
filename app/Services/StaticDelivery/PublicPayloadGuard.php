<?php

namespace App\Services\StaticDelivery;

use App\Services\StaticDelivery\Exceptions\StaticDeliveryException;

final class PublicPayloadGuard
{
    private const FORBIDDEN_KEYS = [
        'password', 'secret', 'token', 'credential', 'authorization', 'private_key',
        'api_key', 'access_key', 'client_secret', 'cookie', 'payment_details',
    ];

    public function validate(array $payload): void
    {
        $this->walk($payload, 'payload');
    }

    private function walk(array $values, string $path): void
    {
        foreach ($values as $key => $value) {
            $normalized = strtolower((string) $key);
            foreach (self::FORBIDDEN_KEYS as $forbidden) {
                if ($normalized === $forbidden || str_ends_with($normalized, '_'.$forbidden)) {
                    throw new StaticDeliveryException('SECRET_KEY_REJECTED', "Public delivery rejected forbidden key {$path}.{$key}.");
                }
            }
            if (is_array($value)) {
                $this->walk($value, $path.'.'.$key);
            }
        }
    }
}
