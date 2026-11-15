<?php

namespace App\Services\Reporting;

use App\Models\ReconciliationRun;

/** Presentation only. Missing provider evidence is not a verified zero. */
final class ReconciliationEvidence
{
    private const FIELDS = ['ad_requests', 'matched_requests', 'impressions', 'clicks', 'gross_revenue_minor'];

    public function forRun(ReconciliationRun $run): array
    {
        $source = $run->source_totals ?? [];
        $stored = $run->stored_totals ?? [];
        $metrics = [];
        foreach (self::FIELDS as $field) {
            $recorded = $run->differences[$field] ?? null;
            if (! is_array($recorded)) continue;
            $expected = $this->integer($source[$field] ?? null);
            $imported = $this->integer($stored[$field] ?? null);
            $difference = $this->integer($recorded['difference'] ?? null);
            // Require both totals and matching comparison inputs, not a status label.
            if ($expected === null || $imported === null || $difference === null
                || $this->integer($recorded['source'] ?? null) !== $expected
                || $this->integer($recorded['stored'] ?? null) !== $imported
                || $imported - $expected !== $difference) continue;
            $metrics[$field] = ['source' => $expected, 'stored' => $imported, 'difference' => $difference];
        }

        return [
            'source_gross' => $this->integer($source['gross_revenue_minor'] ?? null),
            'stored_gross' => $this->integer($stored['gross_revenue_minor'] ?? null),
            'gross_difference' => $metrics['gross_revenue_minor']['difference'] ?? null,
            'metrics' => $metrics,
            'maximum_basis_points' => count($metrics) > 0 && count($metrics) === count($run->differences ?? [])
                ? $this->integer($run->discrepancy_basis_points) : null,
            'compared_count' => count($metrics),
            'supported_count' => count(self::FIELDS),
        ];
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) return $value;
        if (! is_string($value) || ! preg_match('/^-?(0|[1-9][0-9]*)$/D', $value)) return null;
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return $integer === false ? null : $integer;
    }
}
