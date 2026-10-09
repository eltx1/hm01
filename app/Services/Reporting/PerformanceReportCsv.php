<?php

namespace App\Services\Reporting;

use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PerformanceReportCsv
{
    public function download(Collection $days, array $metrics, string $currency, bool $publisher, bool $includeFinality = false): StreamedResponse
    {
        return response()->streamDownload(function () use ($days, $metrics, $currency, $publisher, $includeFinality): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Date', ...array_map(fn ($key) => PerformanceMetrics::label($key, ['has_site_ad_exchange' => $days->contains(fn ($day) => $day['has_site_ad_exchange'] ?? false)]).($key === 'ecpm_minor' ? " ({$currency})" : ''), $metrics),
                ($publisher ? 'Publisher earnings' : 'Gross revenue')." ({$currency})",
                ...($publisher ? ['Estimated earnings', 'Finalized earnings'] : []),
                ...($includeFinality ? ['Includes estimates'] : []),
            ], escape: '');
            foreach ($days as $day) {
                fputcsv($stream, [$day[$publisher ? 'label' : 'date'],
                    ...array_map(fn ($key) => $day[$key] === null ? '' : match ($key) {
                        'ctr_bp', 'viewability_bp' => number_format($day[$key] / 100, 2, '.', '').'%',
                        'ecpm_minor' => number_format($day[$key] / 100, 2, '.', ''),
                        default => $day[$key],
                    }, $metrics),
                    number_format($day[$publisher ? 'earnings_minor' : 'gross_revenue_minor'] / 100, 2, '.', ''),
                    ...($publisher ? [number_format($day['estimated_minor'] / 100, 2, '.', ''), number_format($day['finalized_minor'] / 100, 2, '.', '')] : []),
                    ...($includeFinality ? [($day['has_estimates'] ?? false) ? 'Yes' : 'No'] : []),
                ], escape: '');
            }
            fclose($stream);
        }, 'horus-performance.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
