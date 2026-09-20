<?php

namespace Tests\Unit;

use App\Models\DemandPlacement;
use App\Models\Placement;
use App\Models\PlacementSize;
use App\Services\Demand\CustomThirdPartyTagConnector;
use Illuminate\Database\Eloquent\Collection;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class GoogleAdUnitPathRecipeTest extends TestCase
{
    public function test_a_path_selects_the_actual_display_or_sticky_sizes_and_distinct_container_ids(): void
    {
        foreach (['DISPLAY', 'STICKY'] as $type) {
            $mapping = $this->mapping($type);
            $first = $this->recipe($mapping);
            $mapping->id = 'mapping-two';
            $second = $this->recipe($mapping);

            $this->assertSame('STRUCTURED', $first['executionMode']);
            $this->assertSame('DISPLAY', $first['format']);
            $this->assertSame('/123,456/site.net/display', $first['publicPlacementId']);
            $this->assertSame([[300, 250], [728, 90], 'fluid'], $first['render']['allowedSizes']);
            $this->assertSame('hm-gpt-mapping-one', $first['containerId']);
            $this->assertSame('hm-gpt-mapping-two', $second['containerId']);
            $this->assertSame('NONE', $first['initialization']['type']);
            $this->assertSame('horus-google-gpt-direct-runtime-v1', $first['scripts'][0]['dedupeKey']);
            $this->assertArrayNotHasKey('data-hm-gpt-rewarded', $first['attributes']);
            $this->assertArrayNotHasKey('data-hm-gpt-fit-container', $first['attributes']);
        }
    }

    public function test_responsive_paths_use_container_fitting_without_modifying_sticky_settings(): void
    {
        $mapping = $this->mapping('DISPLAY');
        $mapping->placement->metadata = ['placement_preset' => 'responsive_display'];
        $this->assertSame('1', $this->recipe($mapping)['attributes']['data-hm-gpt-fit-container']);

        $sticky = $this->mapping('STICKY');
        $sticky->placement->metadata = ['placement_preset' => 'sticky_bottom'];
        $sticky->placement->format_settings = ['closeButton' => true, 'bottomOffset' => 20];
        $before = $sticky->placement->getAttributes();
        $this->assertArrayNotHasKey('data-hm-gpt-fit-container', $this->recipe($sticky)['attributes']);
        $this->assertSame($before, $sticky->placement->getAttributes());
    }

    public function test_rewarded_path_keeps_the_existing_rewarded_runtime_and_custom_cooldown(): void
    {
        $mapping = $this->mapping('REWARDED');
        $mapping->placement->format_settings = ['rewardCooldownSeconds' => 125];
        $recipe = $this->recipe($mapping);

        $this->assertSame('REWARDED', $recipe['format']);
        $this->assertSame([], $recipe['render']['allowedSizes']);
        $this->assertSame('1', $recipe['attributes']['data-hm-gpt-rewarded']);
        $this->assertSame('125', $recipe['attributes']['data-hm-reward-cooldown-seconds']);
        $this->assertArrayNotHasKey('data-hm-gpt-sizes', $recipe['attributes']);
        $this->assertSame('hm-gpt-rewarded-mapping-one', $recipe['containerId']);
    }

    public function test_path_cannot_be_interpreted_as_video_or_an_unsupported_out_of_page_surface(): void
    {
        foreach (['VIDEO', 'NATIVE', 'INTERSTITIAL', 'CUSTOM'] as $type) {
            try {
                $this->recipe($this->mapping($type));
                $this->fail('A path must not guess an unsupported placement format: '.$type);
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Display, Sticky, or Rewarded', $exception->getMessage());
            }
        }
    }

    public function test_display_path_without_active_fixed_or_fluid_sizes_fails_closed(): void
    {
        $mapping = $this->mapping('DISPLAY');
        $mapping->placement->setRelation('sizes', new Collection());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no active fixed or fluid display size');
        $this->recipe($mapping);
    }

    private function mapping(string $type): DemandPlacement
    {
        $placement = new Placement(['type' => $type, 'metadata' => []]);
        $placement->setRelation('sizes', new Collection([
            new PlacementSize(['size_type' => 'FIXED', 'width' => 300, 'height' => 250, 'is_active' => true]),
            new PlacementSize(['size_type' => 'FIXED', 'width' => 728, 'height' => 90, 'is_active' => true]),
            new PlacementSize(['size_type' => 'FIXED', 'width' => 300, 'height' => 250, 'is_active' => true]),
            new PlacementSize(['size_type' => 'FIXED', 'width' => 970, 'height' => 250, 'is_active' => false]),
            new PlacementSize(['size_type' => 'FLUID', 'is_active' => true]),
        ]));
        $mapping = new DemandPlacement();
        $mapping->id = 'mapping-one';
        $mapping->setRelation('placement', $placement);

        return $mapping;
    }

    private function recipe(DemandPlacement $placement): array
    {
        $connector = (new ReflectionClass(CustomThirdPartyTagConnector::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(CustomThirdPartyTagConnector::class, 'googleAdUnitPathRecipe');
        $method->setAccessible(true);

        return $method->invoke($connector, '/123,456/site.net/display', [], $placement);
    }
}
