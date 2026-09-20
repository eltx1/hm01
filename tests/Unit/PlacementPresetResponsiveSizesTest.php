<?php

namespace Tests\Unit;

use App\Services\Inventory\PlacementPresetCatalog;
use ReflectionMethod;
use Tests\TestCase;

final class PlacementPresetResponsiveSizesTest extends TestCase
{
    public function test_responsive_has_thirteen_fixed_sizes_and_device_specific_tall_demand(): void
    {
        $preset = $this->preset('responsiveDisplay');
        $mobile = [[300, 250], [336, 280], [320, 100], [320, 50], [300, 100], [300, 50], [250, 250], [200, 200]];
        $all = array_merge($mobile, [[728, 90], [970, 250], [970, 90], [468, 60], [300, 600]]);

        $this->assertSame($all, $this->sizes($preset, 'ALL'));
        $this->assertSame($mobile, $this->sizes($preset, 'MOBILE'));
        $this->assertContains([300, 600], $this->sizes($preset, 'TABLET'));
        $this->assertContains([300, 600], $this->sizes($preset, 'DESKTOP'));
        $this->assertNotContains([970, 90], $this->sizes($preset, 'TABLET'));
        $this->assertNotContains([970, 250], $this->sizes($preset, 'TABLET'));
        $this->assertCount(13, $this->sizes($preset, 'ALL'));
        $this->assertSame(['FIXED'], array_values(array_unique(array_column($preset['sizes'], 'size_type'))));
        $this->assertFalse($preset['format_settings']['autoMount']);
        $this->assertFalse($preset['refresh_enabled']);
        $this->assertTrue($preset['lazy_load_enabled']);
    }

    public function test_existing_in_article_inventory_and_fluid_support_are_not_broadened(): void
    {
        $preset = $this->preset('inArticleDisplay');
        $this->assertSame([[300, 250], [336, 280], [728, 90], [970, 250], [320, 100], [320, 50], [250, 250], [300, 100]], $this->sizes($preset, 'ALL'));
        $this->assertSame([[300, 250], [320, 100], [320, 50], [250, 250], [300, 100]], $this->sizes($preset, 'MOBILE'));
        $this->assertCount(1, array_filter($preset['sizes'], fn (array $size): bool => $size['size_type'] === 'FLUID'));
        $this->assertSame(['reserveSpace' => true, 'autoMount' => false, 'contentPosition' => 'article_mid'], $preset['format_settings']);
        $this->assertFalse($preset['refresh_enabled']);
    }

    public function test_existing_sticky_video_and_rewarded_surface_settings_remain_separate(): void
    {
        foreach (['top', 'bottom'] as $position) {
            $sticky = $this->preset('edgeAnchor', $position);
            $this->assertSame([[300, 50], [300, 100], [320, 50], [320, 100]], $this->sizes($sticky, 'MOBILE'));
            $this->assertSame([[728, 90], [950, 90], [960, 90], [970, 90], [980, 90]], $this->sizes($sticky, 'DESKTOP'));
            $this->assertSame($position, $sticky['format_settings']['position']);
            $this->assertTrue($sticky['format_settings']['autoMount']);
            $this->assertTrue($sticky['format_settings']['closeable']);
            $this->assertFalse($sticky['refresh_enabled']);
        }

        $floating = $this->preset('videoOutstream', true);
        $this->assertSame([[400, 225], [320, 180]], $this->sizes($floating, 'ALL'));
        $this->assertSame('bottom_right', $floating['format_settings']['position']);
        $this->assertTrue($floating['format_settings']['mutedAutoplay']);
        $rewarded = $this->preset('rewardedVideo');
        $this->assertTrue($rewarded['format_settings']['requireUserActivation']);
        $this->assertSame(60, $rewarded['format_settings']['rewardCooldownSeconds']);
    }

    private function preset(string $method, mixed ...$arguments): array
    {
        $catalog = new PlacementPresetCatalog;
        $reflection = new ReflectionMethod($catalog, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($catalog, ...$arguments);
    }

    private function sizes(array $preset, string $device): array
    {
        return array_values(array_map(
            fn (array $size): array => [$size['width'], $size['height']],
            array_filter($preset['sizes'], fn (array $size): bool => $size['device'] === $device && $size['size_type'] === 'FIXED'),
        ));
}
