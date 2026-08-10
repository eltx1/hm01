<?php

namespace Tests\Unit;

use App\Support\Csv;
use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MoneyAndCsvSafetyTest extends TestCase
{
    #[DataProvider('moneyCases')]
    public function test_money_conversion_and_formatting_never_use_floating_point(string $decimal, int $minor, string $formatted): void
    {
        $this->assertSame($minor, Money::decimalToMinor($decimal));
        $this->assertSame($formatted, Money::formatMinor($minor));
    }

    public static function moneyCases(): array
    {
        return [
            ['0', 0, '0.00'],
            ['12.3', 1230, '12.30'],
            ['1234567.89', 123456789, '1,234,567.89'],
            ['-5.01', -501, '-5.01'],
        ];
    }

    public function test_invalid_precision_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::decimalToMinor('10.001');
    }

    public function test_csv_formula_prefixes_are_neutralized(): void
    {
        $this->assertSame("'=SUM(A1:A2)", Csv::safeCell('=SUM(A1:A2)'));
        $this->assertSame("'+123", Csv::safeCell('+123'));
        $this->assertSame('Publisher Site', Csv::safeCell('Publisher Site'));
        $this->assertSame(-100, Csv::safeCell(-100));
    }
}
