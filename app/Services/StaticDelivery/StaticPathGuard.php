<?php

namespace App\Services\StaticDelivery;

use App\Services\StaticDelivery\Exceptions\StaticDeliveryException;

final class StaticPathGuard
{
    public function siteKey(string $siteKey): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]{3,64}$/', $siteKey)) {
            throw new StaticDeliveryException('INVALID_SITE_KEY', 'Site key contains characters that are unsafe for static delivery.');
        }

        return $siteKey;
    }

    public function path(string $path): string
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\\")
            || ! preg_match('#^[A-Za-z0-9_./-]+$#', $path)) {
            throw new StaticDeliveryException('INVALID_PATH', 'Static delivery path is outside the managed allowlist.');
        }

        return $path;
    }
}
