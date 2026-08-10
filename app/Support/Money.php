<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    public static function formatMinor(int $minor): string
    {
        $raw = (string) $minor;
        $negative = str_starts_with($raw, '-');
        $digits = ltrim($raw, '-');
        $digits = str_pad($digits, 3, '0', STR_PAD_LEFT);
        $whole = ltrim(substr($digits, 0, -2), '0');
        $whole = $whole === '' ? '0' : $whole;
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);
        $fraction = substr($digits, -2);

        return ($negative ? '-' : '').$whole.'.'.$fraction;
    }

    public static function decimalToMinor(string|int $value): int
    {
        $normalized = trim((string) $value);
        if (! preg_match('/^(?<sign>-?)(?<whole>\d+)(?:\.(?<fraction>\d{1,2}))?$/', $normalized, $matches)) {
            throw new InvalidArgumentException('Money must be a decimal value with no more than two fractional digits.');
        }

        $whole = (int) $matches['whole'];
        $fraction = str_pad((string) ($matches['fraction'] ?? ''), 2, '0');
        $minor = ($whole * 100) + (int) $fraction;

        return ($matches['sign'] ?? '') === '-' ? -$minor : $minor;
    }
}
