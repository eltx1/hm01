<?php

namespace App\Services\Inventory;

/** Keep current admission and safety controls when restoring advertising settings. */
final class ConfigurationRollbackPolicy
{
    public function preserveCurrentProtection(array $historical, array $current): array
    {
        foreach (['siteKey', 'allowedHostnames', 'trafficGate', 'clickGuard', 'status', 'immediatePause', 'controls', 'recaptchaObservation'] as $key) {
            if (array_key_exists($key, $current)) {
                $historical[$key] = $current[$key];
            } else {
                unset($historical[$key]);
            }
        }

        return $historical;
    }
}
