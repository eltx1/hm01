<?php

namespace App\Services\Inventory;

use App\Enums\PlacementStatus;
use App\Enums\PlacementType;
use App\Models\AdFormat;
use Illuminate\Validation\ValidationException;

final class PlacementPresetCatalog
{
    public const CUSTOM = 'custom';

    public function choices(): array
    {
        return [
            'responsive_display' => [
                'label' => 'Responsive Display',
                'summary' => 'Best default. Automatically maps desktop, tablet, and mobile banner sizes.',
                'badge' => 'Recommended',
                'format' => 'display_banner',
                'type' => PlacementType::Display->value,
                'mount' => 'in-page',
            ],
            'sticky_bottom' => [
                'label' => 'Sticky Bottom',
                'summary' => 'Responsive anchor fixed to the bottom. 728x90 desktop, 320x50 mobile.',
                'badge' => 'Auto mount',
                'format' => 'sticky_anchor',
                'type' => PlacementType::Sticky->value,
                'mount' => 'automatic',
            ],
            'sticky_top' => [
                'label' => 'Sticky Top',
                'summary' => 'Responsive anchor fixed to the top. 728x90 desktop, 320x50 mobile.',
                'badge' => 'Auto mount',
                'format' => 'sticky_anchor',
                'type' => PlacementType::Sticky->value,
                'mount' => 'automatic',
            ],
            'interstitial' => [
                'label' => 'Interstitial',
                'summary' => 'Google out-of-page web interstitial. No page placeholder required.',
                'badge' => 'Auto mount',
                'format' => 'web_interstitial',
                'type' => PlacementType::Interstitial->value,
                'mount' => 'automatic',
            ],
            'side_rail_right' => [
                'label' => 'Right Side Rail',
                'summary' => 'Desktop-only rail fixed to the right side of wide screens.',
                'badge' => 'Desktop',
                'format' => 'side_rail',
                'type' => PlacementType::Sticky->value,
                'mount' => 'automatic',
            ],
            'native_infeed' => [
                'label' => 'Native In-feed',
                'summary' => 'Native placement for an article/feed position supplied by the publisher page.',
                'badge' => 'In-page',
                'format' => 'native_infeed',
                'type' => PlacementType::Native->value,
                'mount' => 'in-page',
            ],
            'video_outstream' => [
                'label' => 'Outstream Video',
                'summary' => 'Responsive outstream video placement with a 16:9 default canvas.',
                'badge' => 'Video',
                'format' => 'video_outstream',
                'type' => PlacementType::Video->value,
                'mount' => 'in-page',
            ],
            'rewarded' => [
                'label' => 'Rewarded',
                'summary' => 'Google rewarded out-of-page placement with Horus rewarded lifecycle events.',
                'badge' => 'Auto mount',
                'format' => 'rewarded',
                'type' => PlacementType::Rewarded->value,
                'mount' => 'automatic',
            ],
            self::CUSTOM => [
                'label' => 'Custom / Advanced',
                'summary' => 'Use raw type, size mapping, targeting, and format JSON controls.',
                'badge' => 'Advanced',
                'format' => null,
                'type' => PlacementType::Custom->value,
                'mount' => 'manual',
            ],
        ];
    }

    public function keys(): array
    {
        return array_keys($this->choices());
    }

    public function apply(string $preset, array $data): array
    {
        $definition = $this->choices()[$preset] ?? null;
        if ($definition === null || $preset === self::CUSTOM) {
            return $data;
        }

        $format = AdFormat::query()->where('code', $definition['format'])->where('is_active', true)->first();
        if (! $format) {
            throw ValidationException::withMessages([
                'placement_preset' => 'The selected placement preset is not available because its ad format is inactive or missing.',
            ]);
        }

        $presetData = match ($preset) {
            'responsive_display' => $this->responsiveDisplay(),
            'sticky_bottom' => $this->stickyAnchor('bottom'),
            'sticky_top' => $this->stickyAnchor('top'),
            'interstitial' => $this->interstitial(),
            'side_rail_right' => $this->sideRailRight(),
            'native_infeed' => $this->nativeInfeed(),
            'video_outstream' => $this->videoOutstream(),
            'rewarded' => $this->rewarded(),
            default => [],
        };

        return array_replace($data, $presetData, [
            'ad_format_id' => $format->id,
            'type' => $definition['type'],
            'status' => PlacementStatus::Active->value,
        ]);
    }

    private function responsiveDisplay(): array
    {
        return [
            'sizes' => array_merge(
                $this->fixed([[300, 250], [336, 280], [728, 90], [970, 250]]),
                $this->responsive('MOBILE', 0, 0, 767, 65535, [[300, 250], [336, 280]]),
                $this->responsive('TABLET', 768, 0, 1023, 65535, [[728, 90], [300, 250], [336, 280]]),
                $this->responsive('DESKTOP', 1024, 0, null, null, [[970, 250], [728, 90], [300, 250], [336, 280]]),
            ),
            'format_settings' => ['reserveSpace' => true],
            'lazy_load_enabled' => true,
            'collapse_empty_div' => true,
            'safeframe_enabled' => false,
            'refresh_enabled' => false,
        ];
    }

    private function stickyAnchor(string $position): array
    {
        return [
            'sizes' => array_merge(
                $this->fixed([[320, 50], [728, 90]]),
                $this->responsive('MOBILE', 0, 0, 767, 65535, [[320, 50]]),
                $this->responsive('DESKTOP', 768, 0, null, null, [[728, 90]]),
            ),
            'format_settings' => [
                'position' => $position,
                'closeable' => true,
                'reserveSpace' => false,
                'autoMount' => true,
            ],
            'lazy_load_enabled' => false,
            'collapse_empty_div' => true,
            'safeframe_enabled' => false,
            'refresh_enabled' => false,
        ];
    }

    private function interstitial(): array
    {
        return [
            'sizes' => [],
            'format_settings' => [
                'autoMount' => true,
                'reserveSpace' => false,
            ],
            'lazy_load_enabled' => false,
            'collapse_empty_div' => false,
            'safeframe_enabled' => false,
            'refresh_enabled' => false,
        ];
    }

    private function sideRailRight(): array
    {
        return [
            'sizes' => array_merge(
                $this->fixed([[160, 600], [300, 600]]),
                $this->responsive('DESKTOP', 1200, 0, null, null, [[160, 600], [300, 600]]),
            ),
            'format_settings' => [
                'position' => 'right',
                'reserveSpace' => false,
                'autoMount' => true,
                'minViewportWidth' => 1200,
            ],
            'lazy_load_enabled' => false,
            'collapse_empty_div' => true,
            'safeframe_enabled' => false,
            'refresh_enabled' => false,
        ];
    }

    private function nativeInfeed(): array
    {
        return [
            'sizes' => [],
            'format_settings' => ['autoMount' => false, 'reserveSpace' => true],
            'lazy_load_enabled' => true,
            'collapse_empty_div' => true,
            'safeframe_enabled' => false,
            'refresh_enabled' => false,
        ];
    }

    private function videoOutstream(): array
    {
        return [
            'sizes' => $this->fixed([[640, 360]]),
            'format_settings' => ['autoMount' => false, 'reserveSpace' => true, 'responsive' => true],
            'lazy_load_enabled' => true,
            'collapse_empty_div' => true,
            'safeframe_enabled' => false,
            'refresh_enabled' => false,
        ];
    }

    private function rewarded(): array
    {
        return [
            'sizes' => $this->fixed([[640, 480]]),
            'format_settings' => ['autoMount' => true, 'reserveSpace' => false],
            'lazy_load_enabled' => false,
            'collapse_empty_div' => false,
            'safeframe_enabled' => false,
            'refresh_enabled' => false,
        ];
    }

    private function fixed(array $sizes): array
    {
        return array_map(fn (array $size) => [
            'size_type' => 'FIXED',
            'width' => $size[0],
            'height' => $size[1],
            'device' => 'ALL',
        ], $sizes);
    }

    private function responsive(string $device, int $minWidth, int $minHeight, ?int $maxWidth, ?int $maxHeight, array $sizes): array
    {
        return array_map(fn (array $size) => [
            'size_type' => 'FIXED',
            'width' => $size[0],
            'height' => $size[1],
            'device' => $device,
            'min_viewport_width' => $minWidth,
            'min_viewport_height' => $minHeight,
            'max_viewport_width' => $maxWidth,
            'max_viewport_height' => $maxHeight,
        ], $sizes);
    }
}
