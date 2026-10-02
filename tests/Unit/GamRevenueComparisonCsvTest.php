<?php

namespace Tests\Unit;

use App\Services\Reporting\GamReportMoneyParser;
use App\Services\Reporting\GamRevenueComparisonCsv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class GamRevenueComparisonCsvTest extends TestCase
{
    // Synthetic fixtures only. Never use private publisher evidence here.
    private const CONTEXT = ['hostname' => 'news.test.example', 'ad_unit_id' => '456', 'network_currency' => 'USD', 'metric_basis' => 'AD_EXCHANGE_V1', 'from' => '2026-09-01', 'to' => '2026-09-30'];

    public function test_exact_site_and_unit_keep_micros_and_signed_daily_rounding(): void
    {
        $result = $this->analyze([
            ['2026-09-01', '456', 'NEWS.TEST.EXAMPLE.', '15000', '3', '1'],
            ['2026-09-02', '456', 'news.test.example', '-5000', '1', '0'],
            ['2026-09-01', '456', 'test.example', '9000', '9', '1'],
            ['2026-09-01', '456', 'other.test.example', '9000', '9', '1'],
            ['2026-09-01', '456', 'www.news.test.example', '9000', '9', '1'],
            ['2026-09-01', '456', 'child.news.test.example', '9000', '9', '1'],
            ['2026-09-01', '456', '(unknown)', '9000', '9', '1'],
            ['2026-09-01', '456', 'https://news.test.example', '9000', '9', '1'],
        ]);
        $this->assertSame(6, $result['excluded_site_rows']);
        $this->assertTrue($result['exact_site_observed']);
        $this->assertSame(['2026-09-01', '2026-09-02'], array_keys($result['days']));
        $this->assertSame(['revenue_micros' => 15000, 'gross_revenue_minor' => 2, 'impressions' => 3, 'clicks' => 1], $result['days']['2026-09-01']);
        $this->assertSame(-5000, $result['days']['2026-09-02']['revenue_micros']);
        $this->assertSame(-1, $result['days']['2026-09-02']['gross_revenue_minor']);
        $this->assertStringNotContainsString('other.test.example', json_encode($result));
    }

    public function test_google_automatic_ad_unit_name_is_allowed_but_never_used_as_scope_or_output(): void
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, ['Dimension.AD_UNIT_NAME', ...self::headers()], escape: '');
        fputcsv($stream, ['Synthetic, descriptive unit name', '2026-09-01', '456', 'news.test.example', '15000', '3', '1'], escape: '');
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        $result = (new GamRevenueComparisonCsv(new GamReportMoneyParser))->analyze($csv, self::CONTEXT, null);
        $this->assertSame($this->analyze([['2026-09-01', '456', 'news.test.example', '15000', '3', '1']]), $result);
        $this->assertStringNotContainsString('Synthetic', json_encode($result));
    }

    public function test_absent_site_is_missing_evidence_and_not_a_zero_daily_row(): void
    {
        $result = $this->analyze([['2026-09-01', '456', 'other.test.example', '0', '0', '0']]);
        $this->assertFalse($result['exact_site_observed']);
        $this->assertSame([], $result['days']);
        $this->assertSame([], $this->analyze([])['days']);
    }

    #[DataProvider('roundingCases')]
    public function test_signed_half_cent_rounding(int $micros, int $minor): void
    {
        $this->assertSame($minor, GamRevenueComparisonCsv::minor($micros));
    }

    public static function roundingCases(): array
    {
        return [[0, 0], [4999, 0], [5000, 1], [14999, 1], [15000, 2], [-4999, 0], [-5000, -1], [-14999, -1], [-15000, -2]];
    }

    #[DataProvider('invalidRows')]
    public function test_invalid_or_out_of_scope_rows_fail_closed(array $row, string $code): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($code);
        $this->analyze([$row]);
    }

    public static function invalidRows(): array
    {
        return [
            [['2026-09-31', '456', 'news.test.example', '1', '1', '0'], 'OUT_OF_SCOPE_DATE'],
            [['2026-08-31', '456', 'news.test.example', '1', '1', '0'], 'OUT_OF_SCOPE_DATE'],
            [['2026-09-1', '456', 'news.test.example', '1', '1', '0'], 'OUT_OF_SCOPE_DATE'],
            [['2026-09-01', '789', 'news.test.example', '1', '1', '0'], 'OUT_OF_SCOPE_UNIT'],
            [['2026-09-01', '456', 'news.test.example', '1', '-1', '0'], 'INVALID_COUNTER'],
            [['2026-09-01', '456', 'news.test.example', '1', '1.0', '0'], 'INVALID_COUNTER'],
            [['2026-09-01', '456', 'news.test.example', '1', '1'], 'INVALID_CSV_ROW'],
        ];
    }

    public function test_normalized_duplicate_grains_are_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DUPLICATE_CSV_ROW');
        $this->analyze([
            ['2026-09-01', '456', 'news.test.example', '1', '1', '0'],
            ['2026-09-01', '456', 'NEWS.TEST.EXAMPLE.', '2', '2', '0'],
        ]);
    }

    public function test_missing_extra_and_duplicate_headers_fail_closed(): void
    {
        $headers = self::headers();
        foreach ([array_slice($headers, 1), [...$headers, 'Column.UNRELATED'], [...$headers, $headers[0]],
            [...$headers, 'Dimension.AD_UNIT_NAME', 'Dimension.AD_UNIT_NAME'],
            [...$headers, 'Dimension.COUNTRY_NAME']] as $bad) {
            try {
                (new GamRevenueComparisonCsv(new GamReportMoneyParser))->analyze(implode(',', $bad)."\n", self::CONTEXT, null);
                $this->fail('Invalid headers were accepted.');
            } catch (RuntimeException $error) {
                $this->assertSame('INVALID_CSV_HEADERS', $error->getMessage());
            }
        }
    }

    #[DataProvider('unprovenCurrencies')]
    public function test_foreign_or_unproven_money_is_rejected(string $amount, ?string $confirmed): void
    {
        $this->expectException(RuntimeException::class);
        $this->analyze([['2026-09-01', '456', 'news.test.example', $amount, '1', '0']], ['network_currency' => 'AED'], $confirmed);
    }

    public static function unprovenCurrencies(): array
    {
        return [['6000', null], ['-6000', null], ['AED 6000', 'USD'], ['EUR 0', 'USD']];
    }

    public function test_job_confirmation_or_explicit_usd_marker_proves_money_currency(): void
    {
        $marked = $this->analyze([['2026-09-01', '456', 'news.test.example', 'USD 6000', '1', '0']], ['network_currency' => 'AED']);
        $confirmed = $this->analyze([['2026-09-01', '456', 'news.test.example', '6000', '1', '0']], ['network_currency' => 'AED'], 'USD');
        $this->assertSame($marked, $confirmed);
    }

    public function test_a_report_from_a_different_metric_contract_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('METRIC_BASIS_CHANGED');
        $this->analyze([], ['metric_basis' => 'LEGACY_TOTAL_UNVERSIONED']);
    }

    public function test_even_excluded_rows_must_have_proven_currency(): void
    {
        $this->expectException(RuntimeException::class);
        $this->analyze([['2026-09-01', '456', 'other.test.example', '6000', '1', '0']], ['network_currency' => 'AED']);
    }

    private static function headers(): array
    {
        return ['Dimension.DATE', 'Dimension.AD_UNIT_ID', 'Dimension.SITE_NAME', 'Column.AD_EXCHANGE_LINE_ITEM_LEVEL_REVENUE', 'Column.AD_EXCHANGE_LINE_ITEM_LEVEL_IMPRESSIONS', 'Column.AD_EXCHANGE_LINE_ITEM_LEVEL_CLICKS'];
    }

    private function analyze(array $rows, array $context = [], ?string $confirmed = null): array
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, self::headers(), escape: '');
        foreach ($rows as $row) fputcsv($stream, $row, escape: '');
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return (new GamRevenueComparisonCsv(new GamReportMoneyParser))->analyze($csv, array_replace(self::CONTEXT, $context), $confirmed);
    }
}
