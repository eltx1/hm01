<?php

namespace Tests\Feature;

use App\Enums\PlacementDevice;
use App\Enums\PlacementType;
use App\Models\DemandPlacement;
use App\Models\Placement;
use App\Models\PlacementSize;
use App\Services\Demand\CustomThirdPartyTagConnector;
use Illuminate\Database\Eloquent\Collection;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class VideoFloatingRecipeTest extends TestCase
{
    public function test_floating_metadata_is_independent_of_the_content_url(): void
    {
        foreach ([null, '', 'https://cdn.horusmedia.net/content.mp4'] as $url) {
            config(['horus.video_content_url' => $url]);
            $recipe = $this->recipe(PlacementType::Video, ['floatingPosition' => 'bottom_right']);
            $attributes = $recipe['container']['attributes'];
            $this->assertSame('1', $attributes['data-hm-video-inline-to-floating']);
            $this->assertSame('https://ads.example/vast', base64_decode($attributes['data-hm-vast-url'], true));
            $this->assertSame($url ? 'accompanying' : null, $attributes['data-hm-video-content-mode'] ?? null);
            $this->assertSame($url ? 'mixed' : 'video_only', $attributes['data-hm-video-ad-format']);
            $this->assertArrayNotHasKey('data-hm-vast-generated', $attributes);
        }
    }

    public function test_linear_only_setting_is_preserved_without_changing_layout_or_cadence(): void
    {
        config(['horus.video_content_url' => 'https://cdn.horusmedia.net/content.mp4']);
        $attributes = $this->recipe(PlacementType::Video, ['videoAdFormat' => 'video_only', 'floatingPosition' => 'bottom_right'])['attributes'];
        $this->assertSame('video_only', $attributes['data-hm-video-ad-format']);
        $this->assertSame('1', $attributes['data-hm-video-inline-to-floating']);
        $this->assertSame('pre,mid,post', $attributes['data-hm-video-breaks']);
        $this->assertSame('0.5', $attributes['data-hm-video-mid-roll-ratio']);
        $this->assertSame('320', $attributes['data-hm-video-width']);
        $this->assertSame('180', $attributes['data-hm-video-height']);
    }

    public function test_rewarded_and_ad_only_inventory_cannot_enable_mixed_ads(): void
    {
        foreach ([null, '', 'http://cdn.horusmedia.net/content.mp4', 'not-a-url'] as $url) {
            config(['horus.video_content_url' => $url]);
            $attributes = $this->recipe(PlacementType::Video, ['videoAdFormat' => 'mixed'])['attributes'];
            $this->assertSame('video_only', $attributes['data-hm-video-ad-format']);
            $this->assertArrayNotHasKey('data-hm-video-content-url', $attributes);
        }
        config(['horus.video_content_url' => 'https://cdn.horusmedia.net/content.mp4']);
        $attributes = $this->recipe(PlacementType::Rewarded, ['videoAdFormat' => 'mixed'])['attributes'];
        $this->assertSame('video_only', $attributes['data-hm-video-ad-format']);
        $this->assertArrayNotHasKey('data-hm-video-content-url', $attributes);
    }

    public function test_inline_only_and_rewarded_are_not_turned_into_floating_inventory(): void
    {
        config(['horus.video_content_url' => 'https://cdn.horusmedia.net/content.mp4']);
        foreach ([PlacementType::Video, PlacementType::Rewarded] as $type) {
            $recipe = $this->recipe($type, []);
            $this->assertArrayNotHasKey('data-hm-video-inline-to-floating', $recipe['container']['attributes']);
        }
    }

    public function test_inventory_classification_and_audio_preference_are_explicit_and_independent(): void
    {
        config(['horus.video_content_url' => 'https://cdn.horusmedia.net/content.mp4']);
        foreach (['accompanying', 'instream'] as $inventory) {
            foreach (['muted', 'prefer_audible'] as $audio) {
                config(['horus.video_inventory_type' => $inventory, 'horus.video_autoplay_audio' => $audio]);
                $attributes = $this->recipe(PlacementType::Video, [])['attributes'];
                $this->assertSame($inventory, $attributes['data-hm-video-content-mode']);
                $this->assertSame($audio === 'prefer_audible' ? '0' : '1', $attributes['data-hm-video-muted']);
                $this->assertSame('1', $attributes['data-hm-video-autoplay']);
                $this->assertSame('mixed', $attributes['data-hm-video-ad-format']);
            }
        }
    }

    public function test_unknown_preferences_preserve_the_existing_accompanying_muted_defaults(): void
    {
        config(['horus.video_content_url' => 'https://cdn.horusmedia.net/content.mp4',
            'horus.video_inventory_type' => 'invalid', 'horus.video_autoplay_audio' => 'invalid']);
        $attributes = $this->recipe(PlacementType::Video, [])['attributes'];
        $this->assertSame('accompanying', $attributes['data-hm-video-content-mode']);
        $this->assertSame('1', $attributes['data-hm-video-muted']);
    }

    public function test_instream_and_sound_preferences_do_not_reclassify_ad_only_or_rewarded_inventory(): void
    {
        config(['horus.video_inventory_type' => 'instream', 'horus.video_autoplay_audio' => 'prefer_audible',
            'horus.video_content_url' => null]);
        $attributes = $this->recipe(PlacementType::Video, [])['attributes'];
        $this->assertArrayNotHasKey('data-hm-video-content-mode', $attributes);
        $this->assertSame('1', $attributes['data-hm-video-muted']);
        config(['horus.video_content_url' => 'https://cdn.horusmedia.net/content.mp4']);
        $attributes = $this->recipe(PlacementType::Rewarded, [])['attributes'];
        $this->assertArrayNotHasKey('data-hm-video-content-mode', $attributes);
        $this->assertSame('0', $attributes['data-hm-video-autoplay']);
    }

    private function recipe(PlacementType $type, array $settings): array
    {
        // Fully populated model relations keep this contract test off the DB and
        // away from external ad endpoints. Test the same recipe code used by Quick Monetize.
        $placement = new Placement(['type' => $type, 'format_settings' => $settings]);
        $placement->setRelation('sizes', new Collection([new PlacementSize([
            'size_type' => 'FIXED', 'width' => 320, 'height' => 180,
            'device' => PlacementDevice::All, 'is_active' => true,
            'min_viewport_width' => 0, 'min_viewport_height' => 0,
        ])]));
        $demand = new DemandPlacement;
        $demand->id = 'video-layout-test';
        $demand->setRelation('placement', $placement);
        $connector = (new ReflectionClass(CustomThirdPartyTagConnector::class))->newInstanceWithoutConstructor();

        return (new ReflectionMethod($connector, 'vastRecipe'))->invoke($connector, ['url' => 'https://ads.example/vast'], [], $demand);
    }
}
