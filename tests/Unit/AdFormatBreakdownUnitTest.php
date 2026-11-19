<?php

namespace Tests\Unit;

use App\Services\Reporting\AdFormatBreakdownService;
use App\Services\Reporting\AdFormatCatalog;
use App\Services\Reporting\SiteGamAdFormatSynchronizer;
use Tests\TestCase;

class AdFormatBreakdownUnitTest extends TestCase
{
    private const HEADER = 'Dimension.DATE,Dimension.AD_UNIT_ID,Dimension.SITE_NAME,Dimension.INVENTORY_FORMAT,Dimension.INVENTORY_FORMAT_NAME,Column.AD_EXCHANGE_TOTAL_REQUESTS,Column.AD_EXCHANGE_RESPONSES_SERVED,Column.AD_EXCHANGE_LINE_ITEM_LEVEL_IMPRESSIONS,Column.AD_EXCHANGE_LINE_ITEM_LEVEL_CLICKS,Column.AD_EXCHANGE_LINE_ITEM_LEVEL_REVENUE';

    private function parse(string $rows, string $header = self::HEADER): array
    {
        return app(SiteGamAdFormatSynchronizer::class)->parse($header."\n".$rows, '123', 'example.com', '2026-10-01', '2026-10-03', 'USD', 'USD', 'USD');
    }

    public function test_known_formats_are_normalized_and_unknown_ones_are_kept(): void
    {
        $this->assertSame('BANNER', AdFormatCatalog::normalize('Banner')['key']);
        $this->assertSame('INTERSTITIAL', AdFormatCatalog::normalize(' interstitial ')['key']);
        $this->assertSame('ANCHOR', AdFormatCatalog::normalize('Anchor')['key']);
        $this->assertSame('REWARDED', AdFormatCatalog::normalize('Rewarded')['key']);
        $this->assertSame('SIDE_RAIL', AdFormatCatalog::normalize('Side rail')['key']);
        $this->assertSame('UNSPECIFIED', AdFormatCatalog::normalize('-')['key']);
        $this->assertSame('UNSPECIFIED', AdFormatCatalog::normalize('(Not applicable)')['key']);
        $this->assertSame('FORMAT_7', AdFormatCatalog::normalize(null, '7')['key']);
        $this->assertSame('SHOPPABLE_GALLERY', AdFormatCatalog::normalize('Shoppable gallery')['key']);
        $this->assertSame('Shoppable gallery', AdFormatCatalog::label('SHOPPABLE_GALLERY', 'Shoppable gallery'));
        $this->assertSame('Rewarded', AdFormatCatalog::label('REWARDED', 'rewarded'));
        $this->assertSame('Not broken down', AdFormatCatalog::label(AdFormatCatalog::NOT_BROKEN_DOWN));
    }

    public function test_largest_remainder_allocation_always_sums_to_the_ledger_amount(): void
    {
        $service = app(AdFormatBreakdownService::class);
        $this->assertSame([34, 33, 33], $service->allocate(100, [1, 1, 1]));
        $this->assertSame([-34, -33, -33], $service->allocate(-100, [1, 1, 1]));
        $this->assertSame([0, 0], $service->allocate(0, [5, 5]));
        $this->assertSame([0, 0], $service->allocate(100, [0, 0]));
        $this->assertSame([], $service->allocate(100, []));
        mt_srand(42);
        for ($i = 0; $i < 200; $i++) {
            $weights = array_map(fn () => mt_rand(0, 9_000_000_000_000), range(1, mt_rand(1, 9)));
            $weights[0]++;
            $amount = mt_rand(-5_000_000, 900_000_000_000);
            $parts = $service->allocate($amount, $weights);
            $this->assertSame($amount, array_sum($parts));
            $this->assertCount(count($weights), $parts);
        }
    }

    public function test_csv_keeps_the_exact_site_merges_same_format_and_handles_missing_active_view(): void
    {
        $rows = $this->parse(implode("\n", [
            '2026-10-01,123,example.com,1,Banner,10,8,8,1,US$ 5000000',
            '2026-10-01,123,EXAMPLE.COM.,2,Interstitial,4,3,3,0,US$ 2000000',
            '2026-10-01,123,www.example.com,1,Banner,99,99,99,9,US$ 99000000',
            '2026-10-01,123,example.com,9,banner,1,1,1,0,US$ 1000000',
        ]));
        $this->assertCount(2, $rows);
        $banner = collect($rows)->firstWhere('ad_format', 'BANNER');
        $this->assertSame(9, $banner['impressions']);
        $this->assertSame(6000000, $banner['revenue_micros']);
        $this->assertNull($banner['active_view_viewable_impressions']);
        $this->assertSame(3, collect($rows)->firstWhere('ad_format', 'INTERSTITIAL')['impressions']);
        $this->assertSame([], $this->parse(''));
    }

    public function test_invalid_or_out_of_scope_csv_is_rejected(): void
    {
        foreach ([
            '2026-09-30,123,example.com,1,Banner,1,1,1,0,US$ 1',
            '2026-10-01,999,example.com,1,Banner,1,1,1,0,US$ 1',
            '2026-10-01,123,example.com,1,Banner,1,1,-1,0,US$ 1',
            '2026-10-01,123,example.com,1,Banner,1,1,1',
            "2026-10-01,123,example.com,1,Banner,1,1,1,0,US$ 1\n2026-10-01,123,example.com,1,Banner,1,1,1,0,US$ 1",
        ] as $csv) {
            try {
                $this->parse($csv);
                $this->fail('Invalid ad format evidence accepted: '.$csv);
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\RuntimeException::class);
        $this->parse('2026-10-01,123,example.com,1,1,1,1,0,US$ 1', 'Dimension.DATE,Dimension.AD_UNIT_ID,Dimension.SITE_NAME,Column.AD_EXCHANGE_TOTAL_REQUESTS,Column.AD_EXCHANGE_RESPONSES_SERVED,Column.AD_EXCHANGE_LINE_ITEM_LEVEL_IMPRESSIONS,Column.AD_EXCHANGE_LINE_ITEM_LEVEL_CLICKS,Column.AD_EXCHANGE_LINE_ITEM_LEVEL_REVENUE');
    }

    public function test_unsupported_google_errors_are_recognized(): void
    {
        $this->assertTrue(SiteGamAdFormatSynchronizer::unsupported(new \RuntimeException('ReportError.COLUMNS_NOT_SUPPORTED_FOR_REQUESTED_DIMENSIONS')));
        $this->assertFalse(SiteGamAdFormatSynchronizer::unsupported(new \RuntimeException('Transient transport unavailable')));
    }
}
