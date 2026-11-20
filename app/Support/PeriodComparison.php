<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/** "vs previous period": the same number of days immediately before the selected range. */
final class PeriodComparison
{
    /** @return array{from: string, to: string} */
    public static function previous(string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();
        $days = (int) $start->diffInDays($end) + 1;

        return ['from' => $start->subDays($days)->toDateString(), 'to' => $start->subDay()->toDateString()];
    }

    /**
     * Rates (CTR, Active View) compare in percentage points; everything else
     * as a relative change. Null when either side is unavailable or the
     * previous value is zero (no honest percentage exists).
     *
     * @return array{direction: string, text: string, value: int}|null
     */
    public static function delta(string $metric, mixed $current, mixed $previous): ?array
    {
        if ($current === null || $previous === null) return null;
        $current = (int) $current;
        $previous = (int) $previous;
        if (in_array($metric, ['ctr_bp', 'viewability_bp'], true)) {
            $change = $current - $previous;
            $text = ($change > 0 ? '+' : '').number_format($change / 100, 2).' pp';
        } else {
            if ($previous <= 0) return null;
            $change = (int) round(($current - $previous) * 10000 / $previous);
            $text = ($change > 0 ? '+' : '').number_format($change / 100, 1).'%';
        }

        return ['direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat'), 'text' => $text, 'value' => $change];
    }
}
