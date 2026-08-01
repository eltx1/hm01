<?php

namespace App\Services\StaticDelivery;

use App\Services\StaticDelivery\Exceptions\StaticDeliveryException;

final class SecretReferenceResolver
{
    public function resolve(?string $reference): string
    {
        $reference = trim((string) $reference);
        if (str_starts_with($reference, 'env:')) {
            $value = env(substr($reference, 4));
        } elseif (str_starts_with($reference, 'file:')) {
            $path = realpath(substr($reference, 5));
            $public = realpath(public_path());
            if (! $path || ! is_readable($path) || ($public && str_starts_with($path, $public.DIRECTORY_SEPARATOR))) {
                throw new StaticDeliveryException('CREDENTIAL_UNREADABLE', 'Static delivery credential file is missing, unreadable, or public.');
            }
            $value = file_get_contents($path);
        } else {
            throw new StaticDeliveryException('CREDENTIAL_REFERENCE_INVALID', 'Static delivery credentials must use an env: or file: reference.');
        }

        if (! is_string($value) || trim($value) === '') {
            throw new StaticDeliveryException('CREDENTIAL_UNAVAILABLE', 'Static delivery credential reference could not be resolved.');
        }

        return trim($value);
    }
}
