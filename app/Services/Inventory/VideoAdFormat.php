<?php

namespace App\Services\Inventory;

use Illuminate\Validation\ValidationException;

final class VideoAdFormat
{
    public const VIDEO_ONLY = 'video_only';
    public const MIXED = 'mixed';

    /** @return array<string, string> */
    public static function choices(): array
    {
        return [self::MIXED => 'Linear video + non-linear overlays', self::VIDEO_ONLY => 'Linear video only'];
    }

    public static function contentUrl(): ?string
    {
        $url = trim((string) config('horus.video_content_url'));

        return $url !== '' && filter_var($url, FILTER_VALIDATE_URL)
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? $url : null;
    }

    public static function resolve(string $type, array $settings): string
    {
        // Non-linear ads require real content underneath. Rewarded and ad-only
        // inventory keep their linear lifecycle even if old settings say mixed.
        if ($type !== 'VIDEO' || self::contentUrl() === null) return self::VIDEO_ONLY;

        return ($settings['videoAdFormat'] ?? null) === self::VIDEO_ONLY ? self::VIDEO_ONLY : self::MIXED;
    }

    public static function apply(array $data, ?string $format): array
    {
        if ($format === null || $format === '') return $data;
        if (! array_key_exists($format, self::choices())) {
            throw ValidationException::withMessages(['video_ad_format' => 'Choose mixed or linear-only video ads.']);
        }
        if (($data['type'] ?? null) !== 'VIDEO') {
            throw ValidationException::withMessages(['video_ad_format' => 'Video ad formats require an ordinary Video placement.']);
        }
        $data['format_settings'] = array_replace((array) ($data['format_settings'] ?? []), ['videoAdFormat' => $format]);

        return $data;
    }
}
