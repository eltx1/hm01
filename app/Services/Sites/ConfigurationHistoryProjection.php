<?php

namespace App\Services\Sites;

/** A small public-field projection; never render provider tags or entire payloads. */
final class ConfigurationHistoryProjection
{
    public function project(array $payload): array
    {
        $result = ['Serving state' => in_array($payload['status'] ?? null, ['active', 'paused'], true) ? $payload['status'] : null];
        foreach (['Emergency pause' => 'immediatePause', 'Cloudflare enabled' => 'trafficGate.enabled', 'Click Guard enabled' => 'clickGuard.enabled', 'GAM enabled' => 'engines.gam.enabled', 'Prebid enabled' => 'prebid.enabled', 'Direct JS enabled' => 'directDemand.enabled'] as $label => $path) {
            $value = data_get($payload, $path);
            $result[$label] = is_bool($value) ? $value : null;
        }
        $placements = $payload['placements'] ?? null;
        $result['Placement count'] = is_array($placements) ? count($placements) : null;

        return $result;
    }

    public function changes(array $payload, ?array $confirmed): array
    {
        $target = $this->project($payload);
        if ($confirmed === null) return ['baseline_available' => false, 'fields' => $target, 'changed' => []];
        $base = $this->project($confirmed);

        return ['baseline_available' => true, 'fields' => $target,
            'changed' => array_keys(array_filter($target, fn ($value, $key) => $value !== $base[$key], ARRAY_FILTER_USE_BOTH))];
    }
}
