<?php

namespace App\Services\Gam;

use Illuminate\Validation\ValidationException;

final class GamCredentialReferenceValidator
{
    public function validate(string $reference): void
    {
        $reference = trim($reference);
        $prefixes = config('gam.credential_reference_prefixes', ['env:', 'file:']);

        if (! collect($prefixes)->contains(fn (string $prefix) => str_starts_with($reference, $prefix))) {
            throw ValidationException::withMessages([
                'credential_reference' => 'Credential references must use an approved env: or file: reference.',
            ]);
        }

        $lower = strtolower($reference);
        $forbidden = ['-----begin private key-----', 'private_key', 'client_secret', 'refresh_token', 'access_token', '{', "\n", "\r"];

        foreach ($forbidden as $fragment) {
            if (str_contains($lower, strtolower($fragment))) {
                throw ValidationException::withMessages([
                    'credential_reference' => 'Raw credential material must never be stored in the database.',
                ]);
            }
        }

        if (mb_strlen($reference) > 1000) {
            throw ValidationException::withMessages([
                'credential_reference' => 'The credential reference is too long.',
            ]);
        }
    }
}
