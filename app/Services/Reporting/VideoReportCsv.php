<?php

namespace App\Services\Reporting;

use Symfony\Component\HttpFoundation\StreamedResponse;

final class VideoReportCsv
{
    public function download(array $video, bool $publisher): StreamedResponse
    {
        return response()->streamDownload(function () use ($video, $publisher): void {
            $stream = fopen('php://output', 'w');
            $currency = $video['currency'];
            fputcsv($stream, ['Date', 'Network reporting timezones (source-date basis)', 'Video impressions', 'Video Unfilled (ad unit, all sites)',
                ($publisher ? 'Video publisher earnings' : 'Video gross revenue')." ({$currency})", "Video eCPM ({$currency})",
                ...($publisher ? [] : ["Video publisher earnings ({$currency})", "Video Horus margin ({$currency})"]), 'Includes estimates'], escape: '');
            foreach ($video['days'] as $day) {
                fputcsv($stream, [$day['date'], implode('; ', $day['timezones'] ?? []), $day['impressions'], $day['unfilled_impressions'],
                    number_format($day['revenue_minor'] / 100, 2, '.', ''),
                    $day['ecpm_minor'] === null ? '' : number_format($day['ecpm_minor'] / 100, 2, '.', ''),
                    ...($publisher ? [] : [number_format($day['publisher_earnings_minor'] / 100, 2, '.', ''), number_format($day['horus_earnings_minor'] / 100, 2, '.', '')]),
                    $day['has_estimates'] ? 'Yes' : 'No'], escape: '');
            }
            fclose($stream);
        }, 'horus-video-performance.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
