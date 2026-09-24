<?php

namespace App\Services\Reporting;

use RuntimeException;

final class GamReportMoneyParser
{
    public function confirmedCurrency(array $reportJob, string $expectedCurrency): ?string
    {
        $currency = data_get($reportJob, 'reportQuery.reportCurrency');
        if ($currency === null || $currency === '') {
            return null;
        }
        if (! is_string($currency) || strtoupper(trim($currency)) !== $expectedCurrency) {
            throw new RuntimeException('Google confirmed an unexpected currency for the GAM report.');
        }

        return $expectedCurrency;
    }

    public function parse(string $value, string $expectedCurrency, string $networkCurrency,
        ?string $confirmedReportCurrency = null): int
    {
        $value = trim($value);
        $marked = false;
        if (preg_match('/^(.+?)\s+(-?\d+)$/uD', $value, $matches) === 1) {
            $prefix = trim($matches[1]);
            $allowed = $expectedCurrency === 'USD' ? ['USD', '$', 'US$'] : [$expectedCurrency];
            if (! in_array($prefix, $allowed, true)) {
                throw new RuntimeException('The Google GAM report returned a monetary value in an unexpected currency.');
            }
            $value = $matches[2];
            $marked = true;
        }
        if (! preg_match('/^-?\d+$/D', $value) || strlen(ltrim($value, '-0')) > 15) {
            throw new RuntimeException('The Google GAM report contains an invalid or oversized revenue value.');
        }

        $micros = (int) $value;
        // Zero needs no exchange rate. For nonzero unmarked money, trust only
        // the network currency or the currency returned by Google for this job,
        // never a local requested-currency setting or another job's checkpoint.
        if ($micros !== 0 && ! $marked && $networkCurrency !== $expectedCurrency
            && $confirmedReportCurrency !== $expectedCurrency) {
            throw new RuntimeException('The Google GAM report did not prove that converted revenue is in the canonical currency.');
        }

        return $micros;
    }
}
