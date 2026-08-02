<?php

namespace Database\Seeders;

use App\Models\AdFormat;
use Illuminate\Database\Seeder;

class AdFormatSeeder extends Seeder
{
    public function run(): void
    {
        $formats = [
            ['display_banner', 'Display banner', 'DISPLAY', 'banner', [[300, 250], [336, 280], [728, 90], [970, 250]], ['responsive' => true, 'refresh' => true], ['reserveSpace' => true]],
            ['display_fluid', 'Fluid / responsive', 'DISPLAY', 'banner', ['fluid'], ['responsive' => true, 'refresh' => true], ['reserveSpace' => true]],
            ['native_infeed', 'Native in-feed', 'NATIVE', 'native', [], ['native' => true, 'refresh' => false], ['nativeContext' => 1]],
            ['video_outstream', 'Outstream video', 'VIDEO', 'video', [[640, 360]], ['vast' => '4.3', 'omid' => '1.5'], ['plcmt' => 4, 'context' => 'outstream']],
            ['sticky_anchor', 'Sticky anchor', 'STICKY', 'banner', [[320, 50], [728, 90]], ['sticky' => true, 'refresh' => true], ['position' => 'bottom', 'closeable' => true, 'reserveSpace' => false]],
            ['web_interstitial', 'Web interstitial', 'INTERSTITIAL', 'banner', [], ['outOfPage' => true], ['triggers' => ['pageLoad', 'unhideWindow'], 'disableBackwardNavigation' => true]],
            ['rewarded', 'Rewarded', 'REWARDED', 'video', [[640, 480]], ['outOfPage' => true, 'rewarded' => true], ['requireReadyEvent' => true, 'requireUserActivation' => true]],
            ['side_rail', 'Desktop side rail', 'STICKY', 'banner', [[160, 600], [300, 600]], ['sticky' => true, 'desktopOnly' => true], ['position' => 'right', 'reserveSpace' => false]],
        ];
        foreach ($formats as $index => [$code, $name, $type, $media, $sizes, $capabilities, $defaults]) {
            AdFormat::query()->updateOrCreate(['code' => $code], [
                'display_name' => $name, 'placement_type' => $type, 'media_type' => $media,
                'default_sizes' => $sizes, 'capabilities' => $capabilities, 'defaults' => $defaults,
                'is_active' => true, 'sort_order' => $index * 10,
            ]);
        }
    }
}
