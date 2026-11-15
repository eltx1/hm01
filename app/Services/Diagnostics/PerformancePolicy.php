<?php

namespace App\Services\Diagnostics;

final class PerformancePolicy
{
    public function configured(): bool
    {
        return config('diagnostics.collector_url') === 'https://metrics.horusmedia.net'
            && is_file(public_path('assets/hm-performance.js'));
    }

    public function publicConfig(?array $observability): array
    {
        $settings = $observability['performance'] ?? [];
        $rate = $settings['sampleBp'] ?? 100;
        if (! $this->configured() || ($settings['enabled'] ?? false) !== true || ! is_int($rate) || $rate < 1 || $rate > 1000) {
            return ['enabled' => false];
        }
        $hash = hash_file('sha256', public_path('assets/hm-performance.js'));
        $name = 'hm-performance.'.substr($hash, 0, 16).'.js';
        $archive = public_path('assets/performance/'.$name);
        if (! is_file($archive) || hash_file('sha256', $archive) !== $hash) return ['enabled' => false];

        return ['enabled' => true, 'sampleBp' => $rate, 'collectorOrigin' => 'https://metrics.horusmedia.net',
            'scriptUrl' => 'https://cdn.horusmedia.net/runtime/performance/'.$name];
    }
}
