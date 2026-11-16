<?php

namespace App\Services\Inventory;

/** Keep current admission and safety controls when restoring advertising settings. */
final class ConfigurationRollbackPolicy
{
    /** Restore layout/targeting while current supply and renderer eligibility remain authoritative. */
    public function preserveCurrentSupply(array $historical, array $current, array $disabledCodes = []): array
    {
        $result = $current;
        foreach (['pageTargeting', 'debug', 'houseAdTesting'] as $key) {
            if (array_key_exists($key, $historical)) $result[$key] = $historical[$key];
        }
        $currentPlacements = [];
        foreach ($current['placements'] ?? [] as $placement) $currentPlacements[$placement['code']] = $placement;
        $placements = [];
        foreach ($historical['placements'] ?? [] as $placement) {
            if (! is_array($placement)) continue;
            $eligible = $currentPlacements[$placement['code'] ?? ''] ?? null;
            if (! $eligible) continue; // Deleted inventory cannot be restored by a configuration rollback.
            $restored = $eligible;
            foreach (['status', 'enabled', 'targeting', 'lazyLoad', 'refresh', 'collapseEmptyDiv'] as $key) {
                if (array_key_exists($key, $placement)) $restored[$key] = $placement[$key];
            }
            $restored['enabled'] = ($placement['enabled'] ?? false) === true
                && ! in_array($placement['code'], $disabledCodes, true)
                && in_array($eligible['renderer'] ?? null, ['GAM', 'PREBID_STANDALONE', 'DIRECT_JS', 'HOUSE'], true)
                && ($eligible['rendererConflict'] ?? true) === false;
            $placements[] = $restored;
            unset($currentPlacements[$placement['code']]);
        }
        $result['placements'] = $placements;

        return $result;
    }

    public function preserveCurrentProtection(array $historical, array $current): array
    {
        foreach (['siteKey', 'allowedHostnames', 'trafficGate', 'clickGuard', 'status', 'immediatePause', 'controls', 'recaptchaObservation'] as $key) {
            if (array_key_exists($key, $current)) {
                $historical[$key] = $current[$key];
            } else {
                unset($historical[$key]);
            }
        }

        // An advertising rollback cannot resurrect a previously enabled collector
        // or a stale endpoint/sample policy. Keep the current optional setting.
        if (array_key_exists('performanceObservation', $current)) {
            $historical['observability']['performance'] = $current['performanceObservation'];
        } else {
            unset($historical['observability']['performance']);
        }

        return $historical;
    }
}
