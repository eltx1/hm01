<?php

namespace App\Services\Inventory;

use Illuminate\Validation\ValidationException;

final class VideoMasterSize
{
    /** @return array<string, array{0:int,1:int}> */
    public static function choices(): array
    {
        return [
            '300x250' => [300, 250],
            '320x180' => [320, 180],
            '336x280' => [336, 280],
            '400x225' => [400, 225],
            '400x300' => [400, 300],
            '640x480' => [640, 480],
        ];
    }

    /**
     * The master defines the media ratio and maximum floating size. Inline
     * media fills its container up to the runtime's inline cap. Generated GAM
     * tags retain this master alongside additional GAM targeting sizes;
     * IMA independently receives actual rendered size.
     * Omitted input deliberately preserves legacy 16:9 inventory and mappings.
     */
    public static function apply(array $data, ?string $master): array
    {
        unset($data['video_master_size']);
        if ($master === null || $master === '') return $data;
        $dimensions = self::choices()[$master] ?? null;
        if ($dimensions === null) {
            throw ValidationException::withMessages(['video_master_size' => 'Choose one of the supported video master sizes.']);
        }
        if (($data['type'] ?? null) !== 'VIDEO') {
            throw ValidationException::withMessages(['video_master_size' => 'A video master size requires an Outstream or Inline → Floating Video placement.']);
        }

        [$width, $height] = $dimensions;
        $base = ['size_type' => 'FIXED', 'width' => $width, 'height' => $height];
        $data['sizes'] = [
            $base + ['device' => 'ALL'],
            $base + ['device' => 'MOBILE', 'min_viewport_width' => 0, 'max_viewport_width' => 767],
            $base + ['device' => 'DESKTOP', 'min_viewport_width' => 768],
        ];
        $data['format_settings'] = array_replace_recursive((array) ($data['format_settings'] ?? []), [
            'videoMasterSize' => $master,
            'responsive' => true,
            'surface' => ['aspectRatio' => $width.':'.$height, 'responsive' => true],
        ]);

        return $data;
    }
}
