<?php

namespace Tests\Unit;

use App\Services\Inventory\PlacementPresetCatalog;
use ReflectionClass;
use Tests\TestCase;

final class PlacementPresetEdgeAnchorCompatibilityTest extends TestCase
{
    public function test_edge_anchor_keeps_reviewed_gpt_passback_sizes_without_opening_arbitrary_display_sizes(): void
    {
        $catalog = app(PlacementPresetCatalog::class);
        $reflection = new ReflectionClass($catalog);
        $method = $reflection->getMethod('edgeAnchor');
        $method->setAccessible(true);

        $preset = $method->invoke($catalog, 'top');
        $sizes = collect($preset['sizes']);

        $fixed = $sizes
            ->filter(fn (array $size): bool => ($size['device'] ?? null) === 'ALL')
            ->map(fn (array $size): array => [(int) $size['width'], (int) $size['height']])
            ->values()
            ->all();
        $mobile = $sizes
            ->filter(fn (array $size): bool => ($size['device'] ?? null) === 'MOBILE')
            ->map(fn (array $size): array => [(int) $size['width'], (int) $size['height']])
            ->values()
            ->all();
        $desktop = $sizes
            ->filter(fn (array $size): bool => ($size['device'] ?? null) === 'DESKTOP')
            ->map(fn (array $size): array => [(int) $size['width'], (int) $size['height']])
            ->values()
            ->all();

        $this->assertSame([
            [120, 90],
            [220, 90],
            [300, 50],
            [300, 100],
            [320, 50],
            [320, 100],
            [728, 90],
            [950, 90],
            [960, 90],
            [970, 90],
            [980, 90],
        ], $fixed);
        $this->assertSame([[300, 50], [300, 100], [320, 50], [320, 100]], $mobile);
        $this->assertSame([[728, 90], [950, 90], [960, 90], [970, 90], [980, 90]], $desktop);
        $this->assertNotContains([300, 250], $fixed);
        $this->assertSame('top', data_get($preset, 'format_settings.position'));
        $this->assertSame('top', data_get($preset, 'format_settings.surface.position'));
        $this->assertSame('edge', data_get($preset, 'format_settings.surface.family'));
        $this->assertSame('auto_body', data_get($preset, 'format_settings.surface.mount'));
        $this->assertTrue((bool) data_get($preset, 'format_settings.autoMount'));
    }
}
