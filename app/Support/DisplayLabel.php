<?php

namespace App\Support;

use BackedEnum;

/**
 * Turns stored codes such as ACTIVE, PENDING_REVIEW or serving_mode values
 * into readable interface labels.
 *
 * Str::headline() splits a single upper-case word into letters
 * ("ACTIVE" → "A C T I V E"), which is how statuses were rendered across the
 * dashboards. This keeps headline's Title Case for every other input while
 * treating upper-case words as words and preserving well-known acronyms.
 */
final class DisplayLabel
{
    private const ACRONYMS = [
        '2FA' => '2FA', 'ADX' => 'AdX', 'AI' => 'AI', 'API' => 'API', 'CCPA' => 'CCPA', 'CMP' => 'CMP',
        'CPA' => 'CPA', 'CPC' => 'CPC', 'CPM' => 'CPM', 'CSV' => 'CSV', 'CTR' => 'CTR', 'DNS' => 'DNS',
        'DSP' => 'DSP', 'ECPM' => 'eCPM', 'EUR' => 'EUR', 'GAM' => 'GAM', 'GDPR' => 'GDPR', 'GIVT' => 'GIVT',
        'HTTP' => 'HTTP', 'HTTPS' => 'HTTPS', 'IAB' => 'IAB', 'IBAN' => 'IBAN', 'ID' => 'ID', 'IP' => 'IP',
        'IVT' => 'IVT', 'KYC' => 'KYC', 'MFA' => 'MFA', 'OTP' => 'OTP', 'PDF' => 'PDF', 'RPM' => 'RPM',
        'SDK' => 'SDK', 'SIVT' => 'SIVT', 'SMS' => 'SMS', 'SSL' => 'SSL', 'SSP' => 'SSP', 'SWIFT' => 'SWIFT',
        'TCF' => 'TCF', 'TLS' => 'TLS', 'TXT' => 'TXT', 'URL' => 'URL', 'USD' => 'USD', 'UTC' => 'UTC',
        'VAT' => 'VAT', 'VAST' => 'VAST',
    ];

    public static function from(BackedEnum|string|int|null $value): string
    {
        $value = $value instanceof BackedEnum ? (string) $value->value : (string) $value;
        $words = preg_split('/[\s_]+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map(self::word(...), $words));
    }

    private static function word(string $word): string
    {
        $upper = mb_strtoupper($word);
        if (isset(self::ACRONYMS[$upper])) {
            return self::ACRONYMS[$upper];
        }
        if ($word === $upper || $word === mb_strtolower($word)) {
            return self::capitalize(mb_strtolower($word));
        }

        // camelCase / PascalCase keys ("sourceGross" → "Source Gross").
        $parts = preg_split('/(?<=\p{Ll})(?=\p{Lu})/u', $word) ?: [$word];

        return implode(' ', array_map(self::capitalize(...), $parts));
    }

    private static function capitalize(string $word): string
    {
        return mb_strtoupper(mb_substr($word, 0, 1)).mb_substr($word, 1);
    }
}
