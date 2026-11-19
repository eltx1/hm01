<?php

namespace App\Services\Reporting;

use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdFormatBreakdownCsv
{
    public function download(array $breakdown, array $metrics, bool $publisher): StreamedResponse
    {
        return response()->streamDownload(function () use ($breakdown, $metrics, $publisher): void {
            $currency = $breakdown['currency'];
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Ad format', ...array_map(fn ($key) => PerformanceMetrics::label($key).($key === 'ecpm_minor' ? " ({$currency})" : ''), $metrics),
                ($publisher ? 'Publisher earnings' : 'Gross revenue')." ({$currency})",
                ...($publisher ? [] : ["Publisher earnings ({$currency})", "Horus margin ({$currency})"]),
                'Share of revenue', 'Includes estimates', 'From', 'To'], escape: '');
            foreach ([...$breakdown['formats'], $breakdown['totals']] as $row) {
                fputcsv($stream, [$row['label'],
                    ...array_map(fn ($key) => $row[$key] === null ? '' : match ($key) {
                        'ctr_bp', 'viewability_bp' => number_format($row[$key] / 100, 2, '.', '').'%',
                        'ecpm_minor' => number_format($row[$key] / 100, 2, '.', ''),
                        default => $row[$key],
                    }, $metrics),
                    number_format($row[$breakdown['revenue_key']] / 100, 2, '.', ''),
                    ...($publisher ? [] : [number_format($row['publisher_earnings_minor'] / 100, 2, '.', ''), number_format($row['horus_earnings_minor'] / 100, 2, '.', '')]),
                    isset($row['share_bp']) ? number_format($row['share_bp'] / 100, 2, '.', '').'%' : ($row['key'] === 'TOTAL' ? '100.00%' : ''),
                    $row['has_estimates'] ? 'Yes' : 'No', $breakdown['from'], $breakdown['to'],
                ], escape: '');
            }
            fclose($stream);
        }, 'horus-ad-format-breakdown.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
