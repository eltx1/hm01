<?php

namespace App\Services\Reporting;

use App\Models\Publisher;
use App\Support\Money;
use App\Support\PeriodComparison;
use Carbon\CarbonImmutable;

/** Publisher-safe earnings summary for a completed day or week (no gross revenue or margin). */
final class PublisherEarningsDigest
{
    public function __construct(private readonly PublisherPerformanceService $performance, private readonly AdFormatBreakdownService $formats) {}

    /** @return array{from: string, to: string, title: string, message: string}|null null when there is nothing to report */
    public function build(Publisher $publisher, string $period, string $endDay): ?array
    {
        $to = CarbonImmutable::parse($endDay);
        $from = $period === 'weekly' ? $to->subDays(6) : $to;
        $current = $this->performance->summary($publisher, $from->toDateString(), $to->toDateString());
        if (! $current['available']) return null;
        $window = PeriodComparison::previous($from->toDateString(), $to->toDateString());
        $previous = $this->performance->summary($publisher, $window['from'], $window['to']);
        $delta = $previous['available'] ? PeriodComparison::delta('earnings_minor', $current['earnings_minor'], $previous['earnings_minor']) : null;
        $sites = \App\Models\Site::withoutGlobalScopes()->where('organization_id', $publisher->organization_id)->where('publisher_id', $publisher->id)->get();
        $breakdown = $this->formats->summary($sites, $from->toDateString(), $to->toDateString(), publisherView: true, publisher: $publisher);
        $top = $breakdown['formats']->reject(fn ($row) => $row['key'] === AdFormatCatalog::NOT_BROKEN_DOWN || ! $row['share_bp'])
            ->take(3)->map(fn ($row) => $row['label'].' '.number_format($row['share_bp'] / 100, 0).'%')->implode(', ');
        $label = $period === 'weekly' ? 'Last 7 days' : 'Yesterday';
        $range = $period === 'weekly' ? $from->format('j M').' – '.$to->format('j M Y') : $to->format('j M Y');
        $currency = $current['currency'];

        return [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'title' => $label.': '.Money::formatMinor($current['earnings_minor']).' '.$currency.' earned',
            'message' => mb_substr($range.': you earned '.Money::formatMinor($current['earnings_minor']).' '.$currency
                .($delta ? ' ('.$delta['text'].' vs the previous '.($period === 'weekly' ? '7 days' : 'day').')' : '').'.'
                .($current['impressions'] ? ' Impressions: '.number_format($current['impressions']).'.' : '')
                .($top !== '' ? ' Top ad formats: '.$top.'.' : '')
                .($current['has_estimates'] ? ' Includes estimates awaiting finalization.' : ''), 0, 500),
        ];
    }
}
