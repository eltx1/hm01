<?php

namespace App\Services\Demand;

use RuntimeException;

final class GoogleVideoAdTag
{
    /**
     * Build a linear GAM VAST template. Page URL, actual size, placement,
     * correlator, playback and consent signals are supplied by the runtime/IMA.
     * https://support.google.com/admanager/answer/10655276
     *
     * @param array{0:int,1:int} $masterSize
     */
    public function build(string $input, array $masterSize): string
    {
        $path = (new GoogleAdUnitPath())->parse($input);
        if ($path === null || preg_match('#/(?:\.|\.\.)(?:/|$)#', $path) || count($masterSize) !== 2 || $masterSize[0] <= 0 || $masterSize[1] <= 0) {
            throw new RuntimeException('A valid GAM video ad unit path and player size are required.');
        }

        return 'https://pubads.g.doubleclick.net/gampad/ads?'.http_build_query([
            'iu' => $path,
            'env' => 'vp',
            'gdfp_req' => '1',
            'output' => 'vast',
            'ad_type' => 'video',
            'vad_type' => 'linear',
            'unviewed_position_start' => '1',
            'sz' => $masterSize[0].'x'.$masterSize[1],
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
