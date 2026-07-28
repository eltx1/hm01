<?php

namespace App\Services\Gam;

use RuntimeException;

final class GamSecretResolver
{
    public function resolveFile(string $reference): string
    {
        $value = match (true) {
            str_starts_with($reference, 'env:') => env(substr($reference, 4)),
            str_starts_with($reference, 'file:') => substr($reference, 5),
            default => null,
        };

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('The GAM credential reference could not be resolved.');
        }

        $path = str_starts_with($value, DIRECTORY_SEPARATOR) ? $value : base_path($value);
        $realPath = realpath($path);

        if ($realPath === false || ! is_file($realPath) || ! is_readable($realPath)) {
            throw new RuntimeException('The referenced GAM credential file is missing or unreadable.');
        }

        $publicPath = realpath(public_path());
        if ($publicPath !== false && str_starts_with($realPath, $publicPath.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('GAM credential files must be stored outside the public directory.');
        }

        return $realPath;
    }

    public function readJson(string $reference): array
    {
        $decoded = json_decode((string) file_get_contents($this->resolveFile($reference)), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('The GAM credential file does not contain a valid JSON object.');
        }

        return $decoded;
    }
}
