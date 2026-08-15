<?php

namespace App\Services\SupplyChain;

final class PublicExtensionGuard
{
    private const SENSITIVE_KEY = '/(?:secret|password|passwd|token|api[_-]?key|private[_-]?key|credential|authorization|cookie|session)/i';

    /** @return list<string> */
    public function validate(mixed $value, string $path = 'ext', int $depth = 0): array
    {
        if (! is_array($value)) {
            return [$path.' must be a JSON object.'];
        }
        if ($depth > 4) {
            return [$path.' exceeds the maximum public extension depth.'];
        }
        if (count($value) > 100) {
            return [$path.' contains too many public extension entries.'];
        }

        $errors = [];
        foreach ($value as $key => $item) {
            $keyPath = $path.'.'.(string) $key;
            if (! array_is_list($value)) {
                if (! is_string($key) || $key === '' || strlen($key) > 64 || preg_match(self::SENSITIVE_KEY, $key)) {
                    $errors[] = $keyPath.' is not an approved safe public extension key.';
                    continue;
                }
            }

            if (is_array($item)) {
                $errors = array_merge($errors, $this->validateNested($item, $keyPath, $depth + 1));
                continue;
            }
            if (is_object($item) || is_resource($item)) {
                $errors[] = $keyPath.' contains an unsupported public extension value.';
                continue;
            }
            if (is_string($item) && (mb_strlen($item) > 2048 || $this->hasUnsafeText($item))) {
                $errors[] = $keyPath.' contains unsafe or unbounded public extension text.';
            }
        }

        return array_values(array_unique($errors));
    }

    /** @return list<string> */
    private function validateNested(array $value, string $path, int $depth): array
    {
        if ($depth > 4) {
            return [$path.' exceeds the maximum public extension depth.'];
        }
        if (count($value) > 100) {
            return [$path.' contains too many public extension entries.'];
        }

        $errors = [];
        $isList = array_is_list($value);
        foreach ($value as $key => $item) {
            $keyPath = $path.'.'.(string) $key;
            if (! $isList && (! is_string($key) || $key === '' || strlen($key) > 64 || preg_match(self::SENSITIVE_KEY, $key))) {
                $errors[] = $keyPath.' is not an approved safe public extension key.';
                continue;
            }
            if (is_array($item)) {
                $errors = array_merge($errors, $this->validateNested($item, $keyPath, $depth + 1));
            } elseif (is_object($item) || is_resource($item)) {
                $errors[] = $keyPath.' contains an unsupported public extension value.';
            } elseif (is_string($item) && (mb_strlen($item) > 2048 || $this->hasUnsafeText($item))) {
                $errors[] = $keyPath.' contains unsafe or unbounded public extension text.';
            }
        }

        return $errors;
    }

    private function hasUnsafeText(string $value): bool
    {
        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1;
    }
}
