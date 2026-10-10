<?php

namespace App\Services\Demand;

use App\Services\Inventory\VideoAdFormat;
use RuntimeException;

final class GoogleVideoAdTag
{
    private const ADDITIONAL_SIZES = [
        '1x1', '288x162', '300x250', '335x200', '400x225', '400x300',
        '419x236', '640x360', '640x480', '1920x1080', '320x480', '444x250',
        '480x320', '480x360', '600x252', '600x338', '720x405', '1024x768', '1280x720',
    ];

    /**
     * Build a GAM VAST 4 template targeting the master plus supported sizes.
     * Actual media dimensions are supplied separately to IMA. Page URL, placement,
     * correlator, playback and consent signals are supplied by the runtime/IMA.
     * https://support.google.com/admanager/answer/10655276
     *
     * @param array{0:int,1:int} $masterSize
     */
    public function build(string $input, array $masterSize, string $adFormat = VideoAdFormat::VIDEO_ONLY): string
    {
        return $this->buildTemplate($input, $masterSize, $adFormat);
    }

    /** @param array{0:int,1:int} $masterSize */
    private function buildTemplate(string $input, array $masterSize, string $adFormat, bool $legacy = false): string
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
            'output' => $legacy ? 'vast' : 'xml_vast4',
            'ad_type' => 'video',
        ];
        // Mixed mode does not add a linearity restriction.
        // ad_type=video still identifies video inventory; do not request display.
        if ($adFormat === VideoAdFormat::VIDEO_ONLY) $query['vad_type'] = 'linear';
        $query += [
            'unviewed_position_start' => '1',
            'sz' => implode('|', array_unique(array_merge(
                [$masterSize[0].'x'.$masterSize[1]], $legacy ? [] : self::ADDITIONAL_SIZES,
            ))),
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
        if (! is_string($query['sz'] ?? null) || ! preg_match('/^([1-9][0-9]*)x([1-9][0-9]*)(?:\||$)/', $query['sz'], $size)) return null;

        try {
            foreach ([VideoAdFormat::VIDEO_ONLY, VideoAdFormat::MIXED] as $previousFormat) {
                // Only exact historical/current generated templates may be
                // rebuilt. A multi-size manual edit is not generated provenance.
                foreach ([false, true] as $legacy) {
                    if ($template === $this->buildTemplate($path, [(int) $size[1], (int) $size[2]], $previousFormat, $legacy)) {
                        return $this->build($path, $masterSize, $adFormat);
                    }
                }
            }
        } catch (RuntimeException) {
            return null;
        }

        return null;
    }
}
