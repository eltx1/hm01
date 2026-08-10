<?php

namespace App\Services\Inventory;

final class RuntimePolicyResolver
{
    /** @return array<string, mixed> */
    public function privacy(?array $settings): array
    {
        return array_replace_recursive([
            'mode' => 'AUTO',
            'cmp' => [
                'tcfVersion' => '2.3',
                'gppVersion' => '1.1',
                'timeoutMs' => 1200,
                'actionOnTimeout' => 'LIMITED_ADS',
            ],
            'signals' => [
                'gpc' => true,
                'coppa' => false,
                'underAgeOfConsent' => false,
            ],
            'requireConsentBeforeAds' => true,
        ], $settings ?? []);
    }

    /** @return array{enabled: bool, maxClicks: int, windowHours: int, blockHours: int} */
    public function clickGuard(?array $settings): array
    {
        $settings ??= [];
        $enabled = filter_var($settings['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return [
            'enabled' => $enabled ?? false,
            'maxClicks' => $this->boundedInteger($settings['maxClicks'] ?? null, 3, 1, 50),
            'windowHours' => $this->boundedInteger($settings['windowHours'] ?? null, 6, 1, 168),
            'blockHours' => $this->boundedInteger($settings['blockHours'] ?? null, 12, 1, 720),
        ];
    }

    private function boundedInteger(mixed $value, int $default, int $minimum, int $maximum): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT);
        if ($validated === false) {
            return $default;
        }

        return max($minimum, min($maximum, $validated));
    }
}
