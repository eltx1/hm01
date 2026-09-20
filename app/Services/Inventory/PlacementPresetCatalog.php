<?php

namespace App\Services\Inventory;

use App\Enums\PlacementStatus;
use App\Enums\PlacementType;
use App\Models\AdFormat;
use Illuminate\Validation\ValidationException;

final class PlacementPresetCatalog
{
    public const CUSTOM = 'custom';

    /**
     * Placement presets describe inventory surfaces, never demand providers.
     * Provider-specific behavior belongs to the serving adapter/connector.
     *
     * @return array<string, array<string, mixed>>
     */
    public function choices(): array
    {
        return [
            'responsive_display' => ['label' => 'Responsive Display', 'summary' => 'Universal multi-size display surface for desktop, tablet, and mobile.', 'badge' => 'Recommended', 'group' => 'Display', 'format' => 'display_banner', 'type' => PlacementType::Display->value, 'mount' => 'in-page', 'quick' => true],
            'in_article_display' => ['label' => 'In-Content Display', 'summary' => 'Responsive display surface auto-mounted inside the page primary content region: article, video, feed, gallery, tool, or app view.', 'badge' => 'Content', 'group' => 'Display', 'format' => 'display_in_article', 'type' => PlacementType::Display->value, 'mount' => 'in-page', 'quick' => true],
            'high_impact_display' => ['label' => 'High-impact Display', 'summary' => 'Larger desktop-first display sizes with responsive fallback.', 'badge' => 'High impact', 'group' => 'Display', 'format' => 'display_high_impact', 'type' => PlacementType::Display->value, 'mount' => 'in-page', 'quick' => true],
            'mobile_display' => ['label' => 'Mobile Multi-size', 'summary' => 'Compact mobile display surface for 320x50, 320x100, and 300x250 demand.', 'badge' => 'Mobile', 'group' => 'Display', 'format' => 'display_mobile', 'type' => PlacementType::Display->value, 'mount' => 'in-page', 'quick' => true],
            'sticky_bottom' => ['label' => 'Bottom Edge Anchor', 'summary' => 'Provider-agnostic closeable edge surface fixed to the bottom of the viewport.', 'badge' => 'Auto mount', 'group' => 'Edge', 'format' => 'anchor_edge', 'type' => PlacementType::Sticky->value, 'mount' => 'automatic', 'quick' => true],
            'sticky_top' => ['label' => 'Top Edge Anchor', 'summary' => 'Provider-agnostic closeable edge surface fixed to the top of the viewport.', 'badge' => 'Auto mount', 'group' => 'Edge', 'format' => 'anchor_edge', 'type' => PlacementType::Sticky->value, 'mount' => 'automatic', 'quick' => true],
            'side_rail_right' => ['label' => 'Right Side Rail', 'summary' => 'Desktop-only vertical rail on wide screens.', 'badge' => 'Desktop', 'group' => 'Edge', 'format' => 'side_rail', 'type' => PlacementType::Sticky->value, 'mount' => 'automatic', 'quick' => true],
            'side_rail_left' => ['label' => 'Left Side Rail', 'summary' => 'Desktop-only vertical rail on wide screens.', 'badge' => 'Desktop', 'group' => 'Edge', 'format' => 'side_rail', 'type' => PlacementType::Sticky->value, 'mount' => 'automatic', 'quick' => true],
            'video_outstream' => ['label' => 'Outstream / In-read Video', 'summary' => 'Responsive 16:9 video surface for outstream or in-read provider tags.', 'badge' => 'Video', 'group' => 'Video', 'format' => 'video_outstream', 'type' => PlacementType::Video->value, 'mount' => 'in-page', 'quick' => true],
            'video_floating' => ['label' => 'Floating Video', 'summary' => 'Responsive floating 16:9 video surface near the lower viewport edge.', 'badge' => 'Auto mount', 'group' => 'Video', 'format' => 'video_floating', 'type' => PlacementType::Video->value, 'mount' => 'automatic', 'quick' => true],
            'native_infeed' => ['label' => 'Native In-feed / Fluid', 'summary' => 'Fluid native surface for an article or feed position. Use a provider adapter or Advanced setup.', 'badge' => 'Fluid', 'group' => 'Native', 'format' => 'native_infeed', 'type' => PlacementType::Native->value, 'mount' => 'in-page', 'quick' => false],
            'native_recommendation' => ['label' => 'Native Recommendation Widget', 'summary' => 'Fluid recommendation/content-discovery surface, usually near article end.', 'badge' => 'Provider managed', 'group' => 'Native', 'format' => 'native_recommendation', 'type' => PlacementType::Native->value, 'mount' => 'in-page', 'quick' => false],
            'interstitial' => ['label' => 'Interstitial / Full-screen', 'summary' => 'Viewport-level interstitial surface. Provider support and lifecycle controls are required.', 'badge' => 'Provider managed', 'group' => 'High impact', 'format' => 'web_interstitial', 'type' => PlacementType::Interstitial->value, 'mount' => 'provider-managed', 'quick' => false],
            'rewarded' => ['label' => 'Rewarded Video / Opt-in', 'summary' => 'Google GPT Rewarded from /NetworkCode/AdUnitCode, compatible rewarded VAST, or a supported provider tag.', 'badge' => 'User initiated', 'group' => 'Video', 'format' => 'rewarded', 'type' => PlacementType::Rewarded->value, 'mount' => 'automatic', 'quickMount' => 'article_end', 'quick' => true],
            'contextual_in_image' => ['label' => 'In-image / Contextual Overlay', 'summary' => 'Contextual overlay surface attached to editorial imagery.', 'badge' => 'Provider managed', 'group' => 'High impact', 'format' => 'contextual_in_image', 'type' => PlacementType::Custom->value, 'mount' => 'provider-managed', 'quick' => false],
            'edge_in_screen' => ['label' => 'In-screen / Edge Overlay', 'summary' => 'Provider-managed overlay attached to a viewport edge.', 'badge' => 'Provider managed', 'group' => 'High impact', 'format' => 'edge_in_screen', 'type' => PlacementType::Sticky->value, 'mount' => 'provider-managed', 'quick' => false],
            'page_skin' => ['label' => 'Page Skin / Takeover', 'summary' => 'Desktop page skin or takeover surface controlled by a compatible provider adapter.', 'badge' => 'Provider managed', 'group' => 'High impact', 'format' => 'page_skin', 'type' => PlacementType::Custom->value, 'mount' => 'provider-managed', 'quick' => false],
            'rich_media_expandable' => ['label' => 'Expandable Rich Media', 'summary' => 'Expandable rich-media surface for compatible providers and reviewed creatives.', 'badge' => 'Provider managed', 'group' => 'High impact', 'format' => 'rich_media_expandable', 'type' => PlacementType::Custom->value, 'mount' => 'provider-managed', 'quick' => false],
            self::CUSTOM => ['label' => 'Custom / Advanced', 'summary' => 'Use raw type, size mapping, targeting, and format JSON controls.', 'badge' => 'Advanced', 'group' => 'Advanced', 'format' => null, 'type' => PlacementType::Custom->value, 'mount' => 'manual', 'quick' => false],
        ];
    }

    public function keys(): array
    {
        return array_keys($this->choices());
    }

    /** @return array<string, array<string, mixed>> */
    public function quickChoices(): array
    {
        return array_filter($this->choices(), fn (array $choice): bool => (bool) ($choice['quick'] ?? false));
    }

    public function apply(string $preset, array $data): array
    {
        $definition = $this->choices()[$preset] ?? null;
        if ($definition === null || $preset === self::CUSTOM) return $data;

        $format = AdFormat::query()->where('code', $definition['format'])->where('is_active', true)->first();
        if (! $format) {
            throw ValidationException::withMessages(['placement_preset' => 'The selected placement preset is not available because its ad format is inactive or missing.']);
        }

        $presetData = match ($preset) {
            'responsive_display' => $this->responsiveDisplay(),
            'in_article_display' => $this->inArticleDisplay(),
            'high_impact_display' => $this->highImpactDisplay(),
            'mobile_display' => $this->mobileDisplay(),
            'sticky_bottom' => $this->edgeAnchor('bottom'),
            'sticky_top' => $this->edgeAnchor('top'),
            'side_rail_right' => $this->sideRail('right'),
            'side_rail_left' => $this->sideRail('left'),
            'video_outstream' => $this->videoOutstream(false),
            'video_floating' => $this->videoOutstream(true),
            'native_infeed' => $this->nativeSurface('article_mid'),
            'native_recommendation' => $this->nativeSurface('article_end'),
            'interstitial' => $this->providerManaged('viewport'),
            'rewarded' => $this->rewardedVideo(),
            'contextual_in_image' => $this->providerManaged('in_image'),
            'edge_in_screen' => $this->providerManaged('screen_edge'),
            'page_skin' => $this->providerManaged('background'),
            'rich_media_expandable' => $this->providerManaged('inline'),
            default => [],
        };

        return array_replace($data, $presetData, ['ad_format_id' => $format->id, 'type' => $definition['type'], 'status' => PlacementStatus::Active->value]);
    }

    private function responsiveDisplay(): array
    {
        return [
            'sizes' => array_merge($this->fixed([[300, 250], [336, 280], [728, 90], [970, 250], [320, 100], [320, 50]]), $this->responsive('MOBILE', 0, 0, 767, 65535, [[300, 250], [320, 100], [320, 50]]), $this->responsive('TABLET', 768, 0, 1023, 65535, [[728, 90], [336, 280], [300, 250]]), $this->responsive('DESKTOP', 1024, 0, null, null, [[970, 250], [728, 90], [336, 280], [300, 250]])),
            'format_settings' => ['reserveSpace' => true, 'autoMount' => false], 'lazy_load_enabled' => true, 'collapse_empty_div' => true, 'safeframe_enabled' => false, 'refresh_enabled' => false,
        ];
    }

    private function inArticleDisplay(): array
    {
        $data = $this->responsiveDisplay();

        // Keep the complete reviewed LordAI in-article compatibility set in
        // one place. 250x250 and 300x100 are both emitted by the site's GAM ad
        // unit alongside the ordinary responsive sizes and native fluid. Scope
        // them to In-Article only instead of broadening every display preset.
        $compatibilitySizes = [[250, 250], [300, 100]];
        $data['sizes'] = array_merge(
            $data['sizes'],
            $this->fixed($compatibilitySizes),
            $this->responsive('MOBILE', 0, 0, 767, 65535, $compatibilitySizes),
            $this->responsive('TABLET', 768, 0, 1023, 65535, $compatibilitySizes),
            $this->responsive('DESKTOP', 1024, 0, null, null, $compatibilitySizes),
        );

        // Google Ad Manager uses the official "fluid" GPT size for native
        // creatives. Keep the ordinary display sizes too so the same in-article
        // surface can serve fixed display and native-fluid demand safely.
        $data['sizes'][] = [
            'size_type' => 'FLUID',
            'width' => null,
            'height' => null,
            'device' => 'ALL',
        ];
        $data['format_settings'] = ['reserveSpace' => true, 'autoMount' => false, 'contentPosition' => 'article_mid'];
        return $data;
    }

    private function highImpactDisplay(): array
    {
        return [
            'sizes' => array_merge($this->fixed([[970, 250], [970, 90], [728, 90], [300, 600], [300, 250]]), $this->responsive('MOBILE', 0, 0, 767, 65535, [[300, 250]]), $this->responsive('TABLET', 768, 0, 1023, 65535, [[728, 90], [300, 600], [300, 250]]), $this->responsive('DESKTOP', 1024, 0, null, null, [[970, 250], [970, 90], [728, 90], [300, 600]])),
            'format_settings' => ['reserveSpace' => true, 'autoMount' => false], 'lazy_load_enabled' => true, 'collapse_empty_div' => true, 'safeframe_enabled' => false, 'refresh_enabled' => false,
        ];
    }

    private function mobileDisplay(): array
    {
        return [
            'sizes' => array_merge($this->fixed([[320, 50], [320, 100], [300, 250]]), $this->responsive('MOBILE', 0, 0, 767, 65535, [[300, 250], [320, 100], [320, 50]])),
            'format_settings' => ['reserveSpace' => true, 'autoMount' => false, 'maxViewportWidth' => 767], 'lazy_load_enabled' => true, 'collapse_empty_div' => true, 'safeframe_enabled' => false, 'refresh_enabled' => false,
        ];
    }

    private function edgeAnchor(string $position): array
    {
        // Keep the allowlist explicit: trusted GPT may preserve reviewed passback
        // banner sizes, while arbitrary display dimensions still fail closed.
        $compactCompatibilitySizes = [[120, 90], [220, 90]];
        $mobileSizes = [[300, 50], [300, 100], [320, 50], [320, 100]];
        $desktopSizes = [[728, 90], [950, 90], [960, 90], [970, 90], [980, 90]];

        return [
            'sizes' => array_merge(
                $this->fixed(array_merge($compactCompatibilitySizes, $mobileSizes, $desktopSizes)),
                $this->responsive('MOBILE', 0, 0, 767, 65535, $mobileSizes),
                $this->responsive('DESKTOP', 768, 0, null, null, $desktopSizes),
            ),
            'format_settings' => [
                'position' => $position,
                'closeable' => true,
                'reserveSpace' => false,
                'autoMount' => true,
                'surface' => [
                    'family' => 'edge',
                    'mount' => 'auto_body',
                    'position' => $position,
                    'responsive' => true,
                    'providerAgnostic' => true,
                ],
            ], 'lazy_load_enabled' => false, 'collapse_empty_div' => true, 'safeframe_enabled' => false, 'refresh_enabled' => false,
        ];
    }

    private function sideRail(string $position): array
    {
        return [
            'sizes' => array_merge($this->fixed([[160, 600], [300, 600]]), $this->responsive('DESKTOP', 1200, 0, null, null, [[160, 600], [300, 600]])),
            'format_settings' => ['position' => $position, 'reserveSpace' => false, 'autoMount' => true, 'minViewportWidth' => 1200], 'lazy_load_enabled' => false, 'collapse_empty_div' => true, 'safeframe_enabled' => false, 'refresh_enabled' => false,
        ];
    }

    private function nativeSurface(string $contentPosition): array
    {
        return ['sizes' => [['size_type' => 'FLUID', 'width' => null, 'height' => null, 'device' => 'ALL']], 'format_settings' => ['autoMount' => false, 'reserveSpace' => true, 'contentPosition' => $contentPosition], 'lazy_load_enabled' => true, 'collapse_empty_div' => true, 'safeframe_enabled' => false, 'refresh_enabled' => false];
    }

    private function videoOutstream(bool $floating): array
    {
        $fixed = $floating ? [[400, 225], [320, 180]] : [[640, 360], [480, 270], [320, 180]];
        return [
            'sizes' => array_merge($this->fixed($fixed), $this->responsive('MOBILE', 0, 0, 767, 65535, [[320, 180]]), $this->responsive('DESKTOP', 768, 0, null, null, $fixed)),
            'format_settings' => [
                'autoMount' => $floating,
                'reserveSpace' => ! $floating,
                'responsive' => true,
                'position' => $floating ? 'bottom_right' : 'inline',
                'closeable' => $floating,
                'closeOutside' => $floating,
                'singleActiveVideo' => true,
                'minimumVisibleRatio' => 0.5,
                'mutedAutoplay' => true,
            ], 'lazy_load_enabled' => ! $floating, 'collapse_empty_div' => true, 'safeframe_enabled' => false, 'refresh_enabled' => false,
        ];
    }

    private function rewardedVideo(): array
    {
        $fixed = [[640, 360], [320, 180]];

        return [
            'sizes' => array_merge(
                $this->fixed($fixed),
                $this->responsive('MOBILE', 0, 0, 767, 65535, [[320, 180]]),
                $this->responsive('DESKTOP', 768, 0, null, null, $fixed),
            ),
            'format_settings' => [
                'autoMount' => true,
                'autoMountTarget' => 'article_end',
                'reserveSpace' => false,
                'responsive' => true,
                'position' => 'viewport',
                'providerManaged' => false,
                'rewarded' => true,
                'requireUserActivation' => true,
                'singleActiveVideo' => true,
                'rewardCooldownSeconds' => 60,
            ],
            'lazy_load_enabled' => false,
            'collapse_empty_div' => false,
            'safeframe_enabled' => false,
            'refresh_enabled' => false,
        ];
    }

    private function providerManaged(string $position): array
    {
        return ['sizes' => [], 'format_settings' => ['autoMount' => false, 'reserveSpace' => false, 'providerManaged' => true, 'position' => $position], 'lazy_load_enabled' => false, 'collapse_empty_div' => false, 'safeframe_enabled' => false, 'refresh_enabled' => false];
    }

    private function fixed(array $sizes): array
    {
        return array_map(fn (array $size) => ['size_type' => 'FIXED', 'width' => $size[0], 'height' => $size[1], 'device' => 'ALL'], $sizes);
    }

    private function responsive(string $device, int $minWidth, int $minHeight, ?int $maxWidth, ?int $maxHeight, array $sizes): array
    {
        return array_map(fn (array $size) => ['size_type' => 'FIXED', 'width' => $size[0], 'height' => $size[1], 'device' => $device, 'min_viewport_width' => $minWidth, 'min_viewport_height' => $minHeight, 'max_viewport_width' => $maxWidth, 'max_viewport_height' => $maxHeight], $sizes);
    }
}
