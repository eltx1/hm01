<?php

namespace App\Services\Gam;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use RuntimeException;

/** Encrypted, private files live in shared storage across atomic releases. */
final class GamManagedCredentials
{
    public function write(array $material, ?string $id = null): string
    {
        $id ??= (string) Str::ulid();
        $id = strtolower($id);
        $path = $this->path($id);
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not prepare private Google credential storage.');
        }
        $temporary = tempnam($directory, '.credential-');
        if ($temporary === false) {
            throw new RuntimeException('Could not prepare Google credential storage.');
        }
        try {
            if (! chmod($temporary, 0600)
                || file_put_contents($temporary, Crypt::encryptString(json_encode($material, JSON_THROW_ON_ERROR)), LOCK_EX) === false
                || ! rename($temporary, $path)) {
                throw new RuntimeException('Could not securely save the Google connection.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return 'managed:'.$id;
    }

    public function read(string $reference): array
    {
        $path = $this->path(substr($reference, 8));
        if (! is_file($path)) {
            throw new RuntimeException('The saved Google connection needs to be connected again.');
        }

        return json_decode(Crypt::decryptString(file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
    }

    public function hasApp(): bool
    {
        return is_file($this->path('oauth-app'));
    }

    private function path(string $id): string
    {
        if ($id !== 'oauth-app' && ! Str::isUlid($id)) {
            throw new RuntimeException('Invalid managed Google credential reference.');
        }

        return rtrim(config('gam.onboarding.storage_path'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.strtolower($id).'.enc';
    }
}
