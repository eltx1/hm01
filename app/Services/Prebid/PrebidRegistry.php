<?php

namespace App\Services\Prebid;

use App\Models\PrebidAdapter;
use Illuminate\Validation\ValidationException;

final class PrebidRegistry
{
    public function normalizeAndValidate(PrebidAdapter $adapter, array $parameters, bool $requireComplete = true): array
    {
        $required = $this->parameterNames($adapter->required_public_parameters ?? []);
        $optional = $this->parameterNames($adapter->optional_public_parameters ?? []);
        $allowed = array_values(array_unique(array_merge($required, $optional)));
        $normalized = [];

        foreach ($parameters as $key => $value) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            if (! in_array($key, $allowed, true)) {
                throw ValidationException::withMessages([
                    'public_parameters' => "{$key} is not a registered public parameter for {$adapter->bidder_code}.",
                ]);
            }
            if (preg_match('/secret|token|password|private|credential/i', $key)) {
                throw ValidationException::withMessages([
                    'public_parameters' => 'Only bidder parameters safe for browser publication may be stored here.',
                ]);
            }
            if (! is_scalar($value) && ! is_array($value) && $value !== null) {
                throw ValidationException::withMessages([
                    'public_parameters' => "{$key} must be a scalar value or a public array.",
                ]);
            }
            if ($value !== null && $value !== '') {
                $normalized[$key] = $value;
            }
        }

        if ($requireComplete) {
            $missing = array_values(array_filter($required, fn (string $key): bool => ! array_key_exists($key, $normalized)));
            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'public_parameters' => 'Missing required public parameters: '.implode(', ', $missing).'.',
                ]);
            }
        }

        ksort($normalized);

        return $normalized;
    }

    public function injectPublisherId(PrebidAdapter $adapter, ?string $publisherId, array $parameters): array
    {
        $publisherParameter = data_get($adapter->metadata, 'publisher_parameter');
        if ($publisherId && is_string($publisherParameter) && $publisherParameter !== '' && ! isset($parameters[$publisherParameter])) {
            $parameters[$publisherParameter] = $publisherId;
        }

        return $parameters;
    }

    private function parameterNames(array $definitions): array
    {
        return collect($definitions)->map(function ($definition, $key): string {
            if (is_string($definition)) {
                return $definition;
            }
            if (is_array($definition) && isset($definition['name'])) {
                return (string) $definition['name'];
            }

            return (string) $key;
        })->filter()->values()->all();
    }
}
