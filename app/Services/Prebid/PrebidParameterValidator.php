<?php

namespace App\Services\Prebid;

use App\Models\PrebidAdapter;
use Illuminate\Validation\ValidationException;

final class PrebidParameterValidator
{
    private const FORBIDDEN_FRAGMENTS = [
        'secret', 'password', 'token', 'private_key', 'authorization', 'credential',
        'refresh_token', 'access_token', 'client_secret',
    ];

    public function validate(PrebidAdapter $adapter, array $parameters, bool $requireAll = false): array
    {
        $allowed = array_values(array_unique(array_merge(
            $adapter->required_public_parameters ?? [],
            $adapter->optional_public_parameters ?? [],
        )));

        foreach (array_keys($parameters) as $key) {
            $this->assertPublicKey((string) $key);
            if (! in_array($key, $allowed, true)) {
                throw ValidationException::withMessages([
                    'public_parameters' => "Parameter {$key} is not registered for bidder {$adapter->bidder_code}.",
                ]);
            }
        }

        if ($requireAll) {
            $missing = array_values(array_filter(
                $adapter->required_public_parameters ?? [],
                fn (string $key) => ! array_key_exists($key, $parameters) || $parameters[$key] === '' || $parameters[$key] === null,
            ));

            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'public_parameters' => 'Missing required bidder parameters: '.implode(', ', $missing).'.',
                ]);
            }
        }

        $this->assertPublicValue($parameters);

        return $parameters;
    }

    public function mergeAndValidate(PrebidAdapter $adapter, array ...$layers): array
    {
        $merged = [];
        foreach ($layers as $layer) {
            $merged = array_replace_recursive($merged, $layer);
        }

        return $this->validate($adapter, $merged, true);
    }

    private function assertPublicKey(string $key): void
    {
        $lower = strtolower($key);
        foreach (self::FORBIDDEN_FRAGMENTS as $fragment) {
            if (str_contains($lower, $fragment)) {
                throw ValidationException::withMessages([
                    'public_parameters' => "Secret-like parameter {$key} cannot be published to browser JavaScript.",
                ]);
            }
        }
    }

    private function assertPublicValue(mixed $value, int $depth = 0): void
    {
        if ($depth > 8) {
            throw ValidationException::withMessages(['public_parameters' => 'Bidder parameter nesting is too deep.']);
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $this->assertPublicKey($key);
                }
                $this->assertPublicValue($item, $depth + 1);
            }
            return;
        }

        if (! is_null($value) && ! is_scalar($value)) {
            throw ValidationException::withMessages(['public_parameters' => 'Bidder parameters must contain only public scalar values and arrays.']);
        }

        if (is_string($value) && mb_strlen($value) > 4000) {
            throw ValidationException::withMessages(['public_parameters' => 'A bidder parameter value is too long.']);
        }
    }
}
