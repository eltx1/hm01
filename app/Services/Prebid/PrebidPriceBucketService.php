<?php

namespace App\Services\Prebid;

use App\Models\PrebidPriceBucket;
use Illuminate\Validation\ValidationException;

final class PrebidPriceBucketService
{
    public function values(PrebidPriceBucket|array $bucket): array
    {
        $ranges = $bucket instanceof PrebidPriceBucket ? $bucket->ranges : $bucket;
        $values = [];
        $previousMax = 0.0;

        foreach ((array) $ranges as $index => $range) {
            $min = isset($range['min']) ? (float) $range['min'] : $previousMax;
            $max = (float) ($range['max'] ?? 0);
            $increment = (float) ($range['increment'] ?? 0);
            $precision = (int) ($range['precision'] ?? 2);

            if ($max < $min || $increment <= 0 || $precision < 0 || $precision > 6) {
                throw ValidationException::withMessages([
                    'ranges' => "Price bucket range {$index} is invalid.",
                ]);
            }

            $scale = 10 ** $precision;
            $start = (int) round($min * $scale);
            $end = (int) round($max * $scale);
            $step = max(1, (int) round($increment * $scale));

            for ($amount = $start; $amount <= $end; $amount += $step) {
                $formatted = number_format($amount / $scale, $precision, '.', '');
                $values[$formatted] = $formatted;
            }
            $previousMax = $max;
        }

        ksort($values, SORT_NUMERIC);

        return array_values($values);
    }

    public function clientConfig(PrebidPriceBucket $bucket): array
    {
        return [
            'buckets' => collect($bucket->ranges)->map(fn (array $range): array => [
                'min' => (float) ($range['min'] ?? 0),
                'max' => (float) $range['max'],
                'increment' => (float) $range['increment'],
                'precision' => (int) ($range['precision'] ?? 2),
            ])->values()->all(),
        ];
    }

    public function defaultRanges(): array
    {
        return [['min' => 0, 'max' => 5, 'increment' => 0.05, 'precision' => 2]];
    }
}
