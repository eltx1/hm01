<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $formats = [
            ['display_banner', 'Responsive Display', 'DISPLAY', 'banner', [[300, 250], [336, 280], [728, 90], [970, 250], [320, 100], [320, 50]], ['responsive' => true, 'multiSize' => true, 'providerAgnostic' => true, 'iabFlexible' => true, 'quickCompatible' => true], ['reserveSpace' => true, 'surface' => ['family' => 'display', 'mount' => 'in_page', 'position' => 'inline', 'responsive' => true, 'providerAgnostic' => true]]],
            ['display_fluid', 'Fluid / Flexible Display', 'DISPLAY', 'banner', ['fluid'], ['responsive' => true, 'fluid' => true, 'providerAgnostic' => true, 'iabFlexible' => true], ['reserveSpace' => true, 'surface' => ['family' => 'display', 'mount' => 'in_page', 'position' => 'inline', 'responsive' => true, 'providerAgnostic' => true]]],
            ['display_in_article', 'In-Article Multi-size', 'DISPLAY', 'banner', [[300, 250], [336, 280], [728, 90], [970, 250]], ['responsive' => true, 'multiSize' => true, 'providerAgnostic' => true, 'quickCompatible' => true, 'autoInsert' => true], ['reserveSpace' => true, 'surface' => ['family' => 'display', 'mount' => 'article_mid', 'position' => 'inline', 'responsive' => true, 'providerAgnostic' => true]]],
            ['display_high_impact', 'High-impact Display', 'DISPLAY', 'banner', [[970, 250], [970, 90], [728, 90], [300, 600]], ['responsive' => true, 'multiSize' => true, 'providerAgnostic' => true, 'highImpact' => true, 'quickCompatible' => true], ['reserveSpace' => true, 'surface' => ['family' => 'display', 'mount' => 'in_page', 'position' => 'inline', 'responsive' => true, 'providerAgnostic' => true]]],
            ['display_mobile', 'Mobile Multi-size', 'DISPLAY', 'banner', [[320, 50], [320, 100], [300, 250]], ['responsive' => true, 'multiSize' => true, 'providerAgnostic' => true, 'mobileFirst' => true, 'quickCompatible' => true], ['reserveSpace' => true, 'surface' => ['family' => 'display', 'mount' => 'in_page', 'position' => 'inline', 'responsive' => true, 'providerAgnostic' => true, 'maxViewportWidth' => 767]]],
            ['anchor_edge', 'Responsive Edge Anchor', 'STICKY', 'banner', [[320, 50], [320, 100], [728, 90], [970, 90]], ['responsive' => true, 'multiSize' => true, 'providerAgnostic' => true, 'sticky' => true, 'quickCompatible' => true, 'gamOutOfPageEnhanced' => true], ['closeable' => true, 'reserveSpace' => false, 'surface' => ['family' => 'edge', 'mount' => 'auto_body', 'position' => 'bottom', 'responsive' => true, 'providerAgnostic' => true]]],
            ['sticky_anchor', 'Sticky Anchor (legacy compatible)', 'STICKY', 'banner', [[320, 50], [728, 90]], ['responsive' => true, 'sticky' => true, 'providerAgnostic' => true, 'quickCompatible' => true, 'gamOutOfPageEnhanced' => true], ['position' => 'bottom', 'closeable' => true, 'reserveSpace' => false, 'surface' => ['family' => 'edge', 'mount' => 'auto_body', 'position' => 'bottom', 'responsive' => true, 'providerAgnostic' => true]]],
            ['side_rail', 'Desktop Side Rail', 'STICKY', 'banner', [[160, 600], [300, 600]], ['responsive' => true, 'sticky' => true, 'desktopOnly' => true, 'providerAgnostic' => true, 'quickCompatible' => true, 'gamOutOfPageEnhanced' => true], ['position' => 'right', 'reserveSpace' => false, 'surface' => ['family' => 'edge', 'mount' => 'auto_body', 'position' => 'right', 'responsive' => true, 'providerAgnostic' => true, 'minViewportWidth' => 1200]]],
            ['native_infeed', 'Native In-feed / Fluid', 'NATIVE', 'native', ['fluid'], ['native' => true, 'responsive' => true, 'providerAgnostic' => true, 'quickCompatible' => true], ['nativeContext' => 1, 'reserveSpace' => true, 'surface' => ['family' => 'native', 'mount' => 'article_mid', 'position' => 'inline', 'responsive' => true, 'providerAgnostic' => true]]],
            ['native_recommendation', 'Native Recommendation Widget', 'NATIVE', 'native', ['fluid'], ['native' => true, 'responsive' => true, 'providerAgnostic' => true, 'recommendation' => true, 'quickCompatible' => true], ['reserveSpace' => true, 'surface' => ['family' => 'native', 'mount' => 'article_end', 'position' => 'inline', 'responsive' => true, 'providerAgnostic' => true]]],
            ['video_outstream', 'Outstream / In-read Video', 'VIDEO', 'video', [[640, 360], [480, 270], [320, 180]], ['vast' => '4.3', 'omid' => '1.5', 'responsive' => true, 'providerAgnostic' => true, 'quickCompatible' => true], ['plcmt' => 4, 'context' => 'outstream', 'reserveSpace' => true, 'surface' => ['family' => 'video', 'mount' => 'article_mid', 'position' => 'inline', 'responsive' => true, 'aspectRatio' => '16:9', 'providerAgnostic' => true]]],
            ['video_floating', 'Floating Video', 'VIDEO', 'video', [[400, 225], [320, 180]], ['vast' => '4.3', 'omid' => '1.5', 'responsive' => true, 'providerAgnostic' => true, 'floating' => true, 'quickCompatible' => true], ['plcmt' => 4, 'context' => 'outstream', 'reserveSpace' => false, 'surface' => ['family' => 'video', 'mount' => 'auto_body', 'position' => 'bottom_right', 'responsive' => true, 'aspectRatio' => '16:9', 'providerAgnostic' => true]]],
            ['web_interstitial', 'Interstitial / Full-screen', 'INTERSTITIAL', 'banner', [], ['outOfPage' => true, 'providerAgnosticSurface' => true, 'requiresProviderSupport' => true, 'quickCompatible' => true, 'gamOutOfPageEnhanced' => true], ['triggers' => ['pageLoad', 'unhideWindow'], 'disableBackwardNavigation' => true, 'reserveSpace' => false, 'surface' => ['family' => 'interstitial', 'mount' => 'provider_managed', 'position' => 'viewport', 'providerAgnostic' => true]]],
            ['rewarded', 'Rewarded / Opt-in', 'REWARDED', 'video', [[640, 480]], ['outOfPage' => true, 'rewarded' => true, 'providerAgnosticSurface' => true, 'requiresProviderSupport' => true, 'requiresUserActivation' => true, 'quickCompatible' => true, 'gamOutOfPageEnhanced' => true], ['requireReadyEvent' => true, 'requireUserActivation' => true, 'reserveSpace' => false, 'surface' => ['family' => 'rewarded', 'mount' => 'provider_managed', 'position' => 'viewport', 'providerAgnostic' => true, 'requiresUserActivation' => true]]],
            ['contextual_in_image', 'In-image / Contextual Overlay', 'CUSTOM', 'rich_media', [[300, 250]], ['providerAgnosticSurface' => true, 'requiresProviderSupport' => true, 'highImpact' => true], ['reserveSpace' => false, 'surface' => ['family' => 'contextual', 'mount' => 'provider_managed', 'position' => 'in_image', 'responsive' => true, 'providerAgnostic' => true]]],
            ['edge_in_screen', 'In-screen / Edge Overlay', 'STICKY', 'rich_media', [[320, 100], [728, 90]], ['responsive' => true, 'providerAgnosticSurface' => true, 'requiresProviderSupport' => true, 'highImpact' => true], ['reserveSpace' => false, 'surface' => ['family' => 'edge', 'mount' => 'provider_managed', 'position' => 'screen_edge', 'responsive' => true, 'providerAgnostic' => true]]],
            ['page_skin', 'Page Skin / Takeover', 'CUSTOM', 'rich_media', [[970, 250]], ['providerAgnosticSurface' => true, 'requiresProviderSupport' => true, 'desktopOnly' => true, 'highImpact' => true], ['reserveSpace' => false, 'surface' => ['family' => 'skin', 'mount' => 'provider_managed', 'position' => 'background', 'responsive' => true, 'providerAgnostic' => true, 'minViewportWidth' => 1024]]],
            ['rich_media_expandable', 'Expandable Rich Media', 'CUSTOM', 'rich_media', [[300, 250], [970, 250]], ['responsive' => true, 'providerAgnosticSurface' => true, 'requiresProviderSupport' => true, 'highImpact' => true], ['reserveSpace' => true, 'surface' => ['family' => 'rich_media', 'mount' => 'in_page', 'position' => 'inline', 'responsive' => true, 'providerAgnostic' => true]]],
        ];

        $now = now();
        foreach ($formats as $index => [$code, $name, $type, $media, $sizes, $capabilities, $defaults]) {
            $existing = DB::table('ad_formats')->where('code', $code)->first();
            $values = [
                'display_name' => $name,
                'placement_type' => $type,
                'media_type' => $media,
                'default_sizes' => json_encode($sizes, JSON_THROW_ON_ERROR),
                'capabilities' => json_encode($capabilities, JSON_THROW_ON_ERROR),
                'defaults' => json_encode($defaults, JSON_THROW_ON_ERROR),
                'is_active' => true,
                'sort_order' => $index * 10,
                'updated_at' => $now,
            ];

            if ($existing) {
                DB::table('ad_formats')->where('id', $existing->id)->update($values);
            } else {
                DB::table('ad_formats')->insert($values + [
                    'id' => (string) Str::ulid(),
                    'code' => $code,
                    'created_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('ad_formats')->whereIn('code', [
            'display_in_article', 'display_high_impact', 'display_mobile', 'anchor_edge',
            'native_recommendation', 'video_floating', 'contextual_in_image',
            'edge_in_screen', 'page_skin', 'rich_media_expandable',
        ])->delete();
    }
};
