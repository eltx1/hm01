<?php

namespace App\Services\Reporting;

/**
 * Stable keys and display labels for Google Ad Manager inventory formats.
 * Unknown Google names are kept as their own sanitized format instead of
 * being folded into a known one.
 */
final class AdFormatCatalog
{
    public const UNSPECIFIED = 'UNSPECIFIED';

    public const NOT_BROKEN_DOWN = 'NOT_BROKEN_DOWN';

    private const KNOWN = [
        'BANNER' => 'Banner',
        'INTERSTITIAL' => 'Interstitial',
        'ANCHOR' => 'Anchor',
        'REWARDED' => 'Rewarded',
        'REWARDED_INTERSTITIAL' => 'Rewarded interstitial',
        'SIDE_RAIL' => 'Side rail',
        'NATIVE' => 'Native',
        'VIDEO' => 'Video',
        'AUDIO' => 'Audio',
        'APP_OPEN' => 'App open',
        'OFFERWALL' => 'Offerwall',
        self::UNSPECIFIED => 'Unspecified format',
        self::NOT_BROKEN_DOWN => 'Not broken down',
    ];

    private const ALIASES = [
        'ANCHORED' => 'ANCHOR', 'ANCHOR_AD' => 'ANCHOR', 'STICKY' => 'ANCHOR',
        'REWARD' => 'REWARDED', 'REWARDED_AD' => 'REWARDED', 'REWARDS' => 'REWARDED', 'REWARDED_WEB' => 'REWARDED',
        'DISPLAY' => 'BANNER', 'BANNER_AD' => 'BANNER',
        'INTERSTITIAL_AD' => 'INTERSTITIAL', 'WEB_INTERSTITIAL' => 'INTERSTITIAL', 'VIGNETTE' => 'INTERSTITIAL',
        'SIDE_RAILS' => 'SIDE_RAIL', 'IN_STREAM_VIDEO' => 'VIDEO', 'INSTREAM_VIDEO' => 'VIDEO', 'OUTSTREAM_VIDEO' => 'VIDEO',
        'NOT_APPLICABLE' => self::UNSPECIFIED, 'N_A' => self::UNSPECIFIED, 'NA' => self::UNSPECIFIED,
        'UNKNOWN' => self::UNSPECIFIED, 'OTHER' => self::UNSPECIFIED, 'NONE' => self::UNSPECIFIED,
    ];

    /** @return array{key: string, name: ?string} */
    public static function normalize(?string $name, ?string $id = null): array
    {
        $clean = $name === null ? '' : trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '');
        $clean = mb_substr($clean, 0, 128);
        $slug = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $clean), '_'));
        if ($slug === '' && $id !== null && $id !== '' && $id !== '-' && $id !== '0') {
            $slug = 'FORMAT_'.substr(preg_replace('/\D+/', '', $id) ?: '0', 0, 20);
        }
        if ($slug === '') {
            return ['key' => self::UNSPECIFIED, 'name' => $clean === '' ? null : $clean];
        }
        $key = self::ALIASES[$slug] ?? $slug;

        return ['key' => substr($key, 0, 64), 'name' => $clean === '' ? null : $clean];
    }

    public static function label(string $key, ?string $googleName = null): string
    {
        if (isset(self::KNOWN[$key])) {
            return self::KNOWN[$key];
        }
        if ($googleName !== null && trim($googleName) !== '') {
            return trim($googleName);
        }
        if (str_starts_with($key, 'FORMAT_')) {
            return 'Format '.substr($key, 7);
        }

        return ucfirst(strtolower(str_replace('_', ' ', $key)));
    }
}
