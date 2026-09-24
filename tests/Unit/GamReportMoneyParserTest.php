<?php

namespace Tests\Unit;

use App\Services\Reporting\GamReportMoneyParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class GamReportMoneyParserTest extends TestCase
{
    #[DataProvider('validAmounts')]
    public function test_money_requires_currency_evidence_except_for_exact_zero(
        string $value, string $expected, string $network, ?string $confirmed, int $micros,
    ): void {
        $this->assertSame($micros, (new GamReportMoneyParser)->parse($value, $expected, $network, $confirmed));
    }

    public static function validAmounts(): array
    {
        return [
            ['0', 'USD', 'AED', null, 0],
            ['-000', 'USD', '', null, 0],
            ['12000', 'USD', 'USD', null, 12000],
            ['USD 12000', 'USD', 'AED', null, 12000],
            ['US$ 12000', 'USD', 'AED', null, 12000],
            ["$\u{00A0}-12000", 'USD', 'AED', null, -12000],
            ['12000', 'USD', 'AED', 'USD', 12000],
            ['-12000', 'USD', 'AED', 'USD', -12000],
            ['EUR 12000', 'EUR', 'AED', null, 12000],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_or_unproven_money_is_rejected(string $value, ?string $confirmed): void
    {
        $this->expectException(RuntimeException::class);
        (new GamReportMoneyParser)->parse($value, 'USD', 'AED', $confirmed);
    }

    public static function invalidAmounts(): array
    {
        return [
            ['1', null], ['-1', null], ['1', 'AED'],
            ['AED 12000', 'USD'], ['AED 0', 'USD'], ['CA$ 12000', 'USD'],
            ['', 'USD'], ['0.0', 'USD'], ['1e6', 'USD'], ['1,000', 'USD'],
            ['9999999999999999', 'USD'], ['USD not-a-number', 'USD'],
        ];
    }

    public function test_only_the_returned_report_query_can_confirm_currency(): void
    {
        $parser = new GamReportMoneyParser;
        $this->assertSame('USD', $parser->confirmedCurrency(['reportQuery' => ['reportCurrency' => 'USD']], 'USD'));
        $this->assertNull($parser->confirmedCurrency(['id' => '1', 'currency' => 'USD'], 'USD'));
        $this->expectException(RuntimeException::class);
        $parser->confirmedCurrency(['reportQuery' => ['reportCurrency' => 'AED']], 'USD');
    }
}
