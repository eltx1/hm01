<?php

namespace App\Services\Demand;

use Illuminate\Validation\ValidationException;

final class DemandCredentialReferenceValidator
{
    public function validate(string $reference): void
    {
        $reference = trim($reference);
        $prefixes = config('demand.credential_reference_prefixes', ['env:', 'file:']);

        if (! collect($prefixes)->contains(fn (string $prefix) => str_starts_with($reference, $prefix))) {
            throw ValidationException::withMessages([
                'credential_reference' => 'Demand credentials must use an approved env: or file: reference.',
            ]);
        }

        $lower = strtolower($reference);
        foreach (['-----begin', 'api_key=', 'token=', 'password=', 'client_secret', 'refresh_token', '{', "\n", "\r"] as $fragment) {
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
