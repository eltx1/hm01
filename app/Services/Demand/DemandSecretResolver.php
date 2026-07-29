<?php

namespace App\Services\Demand;

use App\Models\DemandAccount;
use RuntimeException;

final class DemandSecretResolver
{
    public function resolve(DemandAccount $account, string $key): ?string
    {
        $credential = $account->credentials()
            ->withoutGlobalScopes()
            ->where('credential_key', $key)
            ->first();

        if (! $credential) {
            return null;
        }

        $reference = trim((string) $credential->reference);

        if (str_starts_with($reference, 'env:')) {
            $name = substr($reference, 4);
            $value = env($name);

            return is_scalar($value) ? (string) $value : null;
        }

        if (str_starts_with($reference, 'file:')) {
            $path = substr($reference, 5);
            $real = realpath($path);
            if (! $real || ! is_file($real) || ! is_readable($real)) {
                throw new RuntimeException('The configured demand credential file is not readable.');
            }

            $public = realpath(public_path());
            if ($public && str_starts_with($real, $public.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Demand credential files must remain outside the public web directory.');
            }

            $contents = file_get_contents($real);
            if ($contents === false) {
                throw new RuntimeException('The configured demand credential file could not be read.');
            }

            return trim($contents);
        }

        throw new RuntimeException('Unsupported demand credential reference.');
    }
}
