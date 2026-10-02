<?php

namespace App\Services\Reporting;

use RuntimeException;

/** Private projection only. No rows from this parser can enter an importer. */
final class GamRevenueComparisonCsv
{
    public static function columns(): array
    {
        return array_keys(SiteGamReportMetrics::FINANCE_COLUMNS);
    }

    public function __construct(private readonly GamReportMoneyParser $money) {}

    public function analyze(string $csv, array $context, ?string $confirmedCurrency): array
    {
        if (($context['metric_basis'] ?? null) !== SiteGamReportMetrics::BASIS) throw new RuntimeException('METRIC_BASIS_CHANGED');
        $required = ['Dimension.DATE', 'Dimension.AD_UNIT_ID', 'Dimension.SITE_NAME', ...array_map(fn ($key) => 'Column.'.$key, self::columns())];
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $csv);
        rewind($stream);
        $days = $seen = [];
        $excluded = $rows = 0;
        try {
            $headers = fgetcsv($stream, escape: '');
            if (! is_array($headers)) throw new RuntimeException('INVALID_CSV_HEADERS');
            $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
            // Google automatically includes AD_UNIT_NAME when AD_UNIT_ID is
            // requested. It is descriptive only; never use it for scope or grain.
            $allowed = [...$required, 'Dimension.AD_UNIT_NAME'];
            if (array_diff($required, $headers) || array_diff($headers, $allowed) || count(array_unique($headers)) !== count($headers)) {
                throw new RuntimeException('INVALID_CSV_HEADERS');
            }
            while (($values = fgetcsv($stream, escape: '')) !== false) {
                if ($values === [null]) continue;
                if (++$rows > 100000 || count($values) !== count($headers)) throw new RuntimeException('INVALID_CSV_ROW');
                $row = array_combine($headers, $values);
                $day = $row['Dimension.DATE'];
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
                if (! $date || $date->format('Y-m-d') !== $day || $day < $context['from'] || $day > $context['to']) throw new RuntimeException('OUT_OF_SCOPE_DATE');
                if ($row['Dimension.AD_UNIT_ID'] !== $context['ad_unit_id']) throw new RuntimeException('OUT_OF_SCOPE_UNIT');
                // Same normalization as the forward importer. No URL, suffix,
                // parent-domain, www or sibling expansion of Google's Site value.
                $host = strtolower(preg_replace('/\.$/D', '', trim($row['Dimension.SITE_NAME'])));
                $identity = $day.'|'.$host;
                if (isset($seen[$identity])) throw new RuntimeException('DUPLICATE_CSV_ROW');
                $seen[$identity] = true;
                $micros = $this->money->parse($row['Column.AD_EXCHANGE_LINE_ITEM_LEVEL_REVENUE'], 'USD', $context['network_currency'], $confirmedCurrency);
                $metrics = [
                    'revenue_micros' => $micros,
                    'gross_revenue_minor' => self::minor($micros),
                    'impressions' => $this->counter($row['Column.AD_EXCHANGE_LINE_ITEM_LEVEL_IMPRESSIONS']),
                    'clicks' => $this->counter($row['Column.AD_EXCHANGE_LINE_ITEM_LEVEL_CLICKS']),
                ];
                if ($host !== $context['hostname']) { $excluded++; continue; }
                $days[$day] = $metrics;
            }
        } finally {
            fclose($stream);
        }
        ksort($days);
        return ['days' => $days, 'source_rows' => $rows, 'excluded_site_rows' => $excluded, 'exact_site_observed' => $days !== []];
    }

    public static function minor(int $micros): int
    {
        return ($micros < 0 ? -1 : 1) * intdiv(abs($micros) + 5000, 10000);
    }

    private function counter(string $value): int
    {
        if (! preg_match('/^\d{1,15}$/D', $value)) throw new RuntimeException('INVALID_COUNTER');
        return (int) $value;
    }
}
