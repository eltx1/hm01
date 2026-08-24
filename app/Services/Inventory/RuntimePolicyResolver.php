<?php

namespace App\Services\Inventory;

use App\Services\Settings\GlobalSettingsService;

final class RuntimePolicyResolver
{
    public function __construct(private readonly GlobalSettingsService $settings) {}

    /** @return array<string, mixed> */
    public function privacy(?array $settings): array
    {
        return array_replace_recursive([
            'mode' => 'AUTO',
            'cmp' => [
                'tcfVersion' => '2.3',
                'gppVersion' => '1.1',
                'tcfRequired' => false,
                'gppRequired' => false,
                'googleCmpEvidenceRequired' => false,
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
        $global = $this->globalClickGuard();
        $inheritGlobal = filter_var($settings['inheritGlobal'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

        if ($inheritGlobal) {
            return $global;
        }

        $enabled = filter_var($settings['enabled'] ?? $global['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return [
            'enabled' => $enabled ?? $global['enabled'],
            'maxClicks' => $this->boundedInteger($settings['maxClicks'] ?? null, $global['maxClicks'], 1, 50),
            'windowHours' => $this->boundedInteger($settings['windowHours'] ?? null, $global['windowHours'], 1, 168),
            'blockHours' => $this->boundedInteger($settings['blockHours'] ?? null, $global['blockHours'], 1, 720),
        ];
    }

    /** @return array{enabled: bool, maxClicks: int, windowHours: int, blockHours: int} */
    public function globalClickGuard(): array
    {
        return [
            'enabled' => (bool) $this->settings->get('click_guard.enabled'),
            'maxClicks' => $this->boundedInteger($this->settings->get('click_guard.max_clicks'), 3, 1, 50),
            'windowHours' => $this->boundedInteger($this->settings->get('click_guard.window_hours'), 6, 1, 168),
            'blockHours' => $this->boundedInteger($this->settings->get('click_guard.block_hours'), 12, 1, 720),
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
