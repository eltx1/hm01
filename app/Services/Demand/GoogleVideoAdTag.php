<?php

namespace App\Services\Demand;

use App\Services\Inventory\VideoAdFormat;
use RuntimeException;

final class GoogleVideoAdTag
{
    /**
     * Build a GAM VAST template targeting the selected master size.
     * Actual media dimensions are supplied separately to IMA. Page URL, placement,
     * correlator, playback and consent signals are supplied by the runtime/IMA.
     * https://support.google.com/admanager/answer/10655276
     *
     * @param array{0:int,1:int} $masterSize
     */
    public function build(string $input, array $masterSize, string $adFormat = VideoAdFormat::VIDEO_ONLY): string
    {
        $path = (new GoogleAdUnitPath())->parse($input);
        if ($path === null || preg_match('#/(?:\.|\.\.)(?:/|$)#', $path) || count($masterSize) !== 2 || $masterSize[0] <= 0 || $masterSize[1] <= 0) {
            throw new RuntimeException('A valid GAM video ad unit path and player size are required.');
        }
        if (! array_key_exists($adFormat, VideoAdFormat::choices())) {
            throw new RuntimeException('A supported video ad format is required.');
        }

        $query = [
            'iu' => $path,
            'env' => 'vp',
            'gdfp_req' => '1',
            'output' => 'vast',
            'ad_type' => 'video',
        ];
        // Mixed mode does not add a linearity restriction.
        // ad_type=video still identifies video inventory; do not request display.
        if ($adFormat === VideoAdFormat::VIDEO_ONLY) $query['vad_type'] = 'linear';
        $query += [
            'unviewed_position_start' => '1',
            'sz' => $masterSize[0].'x'.$masterSize[1],
        ];

        return 'https://pubads.g.doubleclick.net/gampad/ads?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Called only with widget-level GAM_VIDEO_PATH provenance. Recognize our
     * exact old/new templates before rebuilding; an edited URL with ad rules,
     * privacy, custom parameters or a different restriction remains untouched.
     *
     * @param array{0:int,1:int} $masterSize
     */
    public function regenerate(string $template, string $path, array $masterSize, string $adFormat): ?string
    {
        parse_str((string) parse_url($template, PHP_URL_QUERY), $query);
        if (! is_string($query['sz'] ?? null) || ! preg_match('/^([1-9][0-9]*)x([1-9][0-9]*)$/', $query['sz'], $size)) return null;

        try {
            foreach ([VideoAdFormat::VIDEO_ONLY, VideoAdFormat::MIXED] as $previousFormat) {
                if ($template === $this->build($path, [(int) $size[1], (int) $size[2]], $previousFormat)) {
                    return $this->build($path, $masterSize, $adFormat);
                }
            }
        } catch (RuntimeException) {
            return null;
        }

        return null;
    }
}
