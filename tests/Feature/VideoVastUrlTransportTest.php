<?php

namespace Tests\Feature;

use App\Enums\PlacementDevice;
use App\Enums\PlacementType;
use App\Models\DemandPlacement;
use App\Models\Placement;
use App\Models\PlacementSize;
use App\Services\Demand\CustomThirdPartyTagConnector;
use App\Services\Demand\DemandConfigurationBuilder;
use App\Services\Demand\VastTagUrlParser;
use App\Services\Security\PublicProviderOriginValidator;
use App\Services\StaticDelivery\CanonicalJson;
use App\Services\StaticDelivery\PublicPayloadGuard;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class VideoVastUrlTransportTest extends TestCase
{
    #[DataProvider('supportedUrlLengths')]
    public function test_vast_urls_survive_recipe_sanitization_and_static_json_losslessly(int $length, bool $chunked): void
    {
        $url = self::urlOfLength($length);
        $validator = new class extends PublicProviderOriginValidator
        {
            protected function resolveAddresses(string $host): array { return ['93.184.216.34']; }
        };
        $parsed = (new VastTagUrlParser($validator))->parse($url);
        $this->assertSame($url, $parsed['url']);

        foreach ([PlacementType::Video, PlacementType::Rewarded] as $type) {
            $recipe = $this->recipe($url, $type);
            $published = $this->sanitize($recipe);
            app(PublicPayloadGuard::class)->validate($published);
            $roundTrip = json_decode(app(CanonicalJson::class)->encode($published), true, 512, JSON_THROW_ON_ERROR);
            $attributes = $roundTrip['container']['attributes'];
            $this->assertSame($attributes, $roundTrip['attributes']);
            $this->assertSame($url, self::decode($attributes));
            $this->assertSame($chunked, isset($attributes['data-hm-vast-url-parts']));
            $this->assertTrue(collect($attributes)->every(fn ($value) => strlen($value) <= 2000));
            $this->assertSame(60_000, $roundTrip['render']['timeoutMs']);
            $this->assertSame(60_000, $roundTrip['renderTimeoutMs']);

            if ($chunked) {
                $this->assertArrayNotHasKey('data-hm-vast-url', $attributes);
                $this->assertLessThanOrEqual(8, (int) $attributes['data-hm-vast-url-parts']);
            } else {
                $this->assertSame(base64_encode($url), $attributes['data-hm-vast-url']);
            }
            $hash = substr(hash_file('sha256', public_path('assets/hm-video-direct.js')), 0, 16);
            $this->assertSame('https://cdn.horusmedia.net/runtime/video/hm-video-direct.'.$hash.'.js', $roundTrip['scripts'][0]['url']);
            $this->assertSame($roundTrip['scripts'][0]['url'], $roundTrip['scriptUrl']);
        }
    }

    public static function supportedUrlLengths(): array
    {
        return [
            'ordinary short URL' => [400, false],
            'single attribute at 2000 base64 chars' => [1500, false],
            'first chunked URL' => [1501, true],
            'parser byte limit' => [10_000, true],
        ];
    }

    #[DataProvider('invalidAttributes')]
    public function test_malformed_vast_transport_is_rejected_before_any_attribute_is_truncated(array $attributes): void
    {
        $recipe = $this->recipe(self::urlOfLength(400));
        unset($recipe['container']['attributes']['data-hm-vast-url']);
        $recipe['container']['attributes'] += $attributes;
        $this->expectException(RuntimeException::class);
        $this->sanitize($recipe);
    }

    public static function invalidAttributes(): array
    {
        $short = base64_encode(self::urlOfLength(400));
        $long = base64_encode(self::urlOfLength(1501));
        $parts = ['data-hm-vast-url-parts' => '2', 'data-hm-vast-url-0' => substr($long, 0, 1800), 'data-hm-vast-url-1' => substr($long, 1800)];
        $overLimit = str_split(base64_encode(self::urlOfLength(10_001)), 1800);
        $overLimitAttributes = ['data-hm-vast-url-parts' => (string) count($overLimit)];
        foreach ($overLimit as $index => $part) $overLimitAttributes['data-hm-vast-url-'.$index] = $part;

        return [
            'oversized legacy single attribute' => [['data-hm-vast-url' => $long]],
            'empty URL' => [['data-hm-vast-url' => '']],
            'invalid base64' => [['data-hm-vast-url' => '%%%bad%%']],
            'noncanonical base64' => [['data-hm-vast-url' => $short."\n"]],
            'non HTTPS URL' => [['data-hm-vast-url' => base64_encode('http://ads.vendor.net/vast')]],
            'embedded credentials' => [['data-hm-vast-url' => base64_encode('https://user:pass@ads.vendor.net/vast')]],
            'non URL payload' => [['data-hm-vast-url' => base64_encode('not a URL')]],
            'mixed encodings' => [$parts + ['data-hm-vast-url' => $short]],
            'missing count' => [array_diff_key($parts, ['data-hm-vast-url-parts' => true])],
            'missing chunk' => [array_diff_key($parts, ['data-hm-vast-url-1' => true])],
            'extra chunk' => [$parts + ['data-hm-vast-url-2' => 'AAAA']],
            'out of range chunk' => [$parts + ['data-hm-vast-url-8' => 'AAAA']],
            'unknown suffix' => [$parts + ['data-hm-vast-url-extra' => 'AAAA']],
            'oversized chunk' => [array_replace($parts, ['data-hm-vast-url-0' => str_repeat('A', 2004)])],
            'truncated nonfinal chunk' => [array_replace($parts, ['data-hm-vast-url-0' => substr($long, 0, 1796)])],
            'empty final chunk' => [array_replace($parts, ['data-hm-vast-url-1' => ''])],
            'malformed final chunk' => [array_replace($parts, ['data-hm-vast-url-1' => 'AAAA%'])],
            'zero count' => [array_replace($parts, ['data-hm-vast-url-parts' => '0'])],
            'excessive count' => [array_replace($parts, ['data-hm-vast-url-parts' => '9'])],
            'noncanonical count' => [array_replace($parts, ['data-hm-vast-url-parts' => '02'])],
            'fractional count' => [array_replace($parts, ['data-hm-vast-url-parts' => '2.5'])],
            'numeric count value' => [array_replace($parts, ['data-hm-vast-url-parts' => 2])],
            'array chunk value' => [array_replace($parts, ['data-hm-vast-url-0' => ['bad']])],
            'HTML case normalization ambiguity' => [$parts + ['DATA-HM-VAST-URL' => $short]],
            'decoded URL over parser byte limit' => [$overLimitAttributes],
        ];
    }

    public function test_non_video_attribute_and_timeout_limits_are_unchanged(): void
    {
        $recipe = $this->recipe(self::urlOfLength(400));
        unset($recipe['container']['attributes']['data-hm-video-direct'], $recipe['container']['attributes']['data-hm-vast-url']);
        $recipe['container']['attributes']['data-provider-description'] = str_repeat('x', 2001);
        $recipe['render']['timeoutMs'] = 90_000;
        $this->assertSame(10_000, $this->sanitize($recipe)['render']['timeoutMs']);
        $this->assertSame(2000, strlen($this->sanitize($recipe)['container']['attributes']['data-provider-description']));
        $recipe['container']['attributes']['data-hm-gpt-direct'] = '1';
        $this->assertSame(30_000, $this->sanitize($recipe)['render']['timeoutMs']);
        $recipe['container']['attributes']['data-hm-video-direct'] = '1';
        $this->assertSame(60_000, $this->sanitize($recipe)['render']['timeoutMs']);
    }

    private static function urlOfLength(int $length): string
    {
        $base = 'https://ads.vendor.net/vast?iu=/123/video&sz=400x225&correlator=[timestamp]&description_url=[description_url]&cust_params=a%3Db%26c%3Dd&padding=';
        return $base.str_repeat('x', $length - strlen($base));
    }

    private static function decode(array $attributes): string
    {
        $encoded = $attributes['data-hm-vast-url'] ?? '';
        for ($index = 0; $index < (int) ($attributes['data-hm-vast-url-parts'] ?? 0); $index++) {
            $encoded .= $attributes['data-hm-vast-url-'.$index];
        }
        return base64_decode($encoded, true);
    }

    private function sanitize(array $recipe): array
    {
        $builder = (new ReflectionClass(DemandConfigurationBuilder::class))->newInstanceWithoutConstructor();
        return (new ReflectionMethod($builder, 'sanitizePublicTag'))->invoke($builder, $recipe);
    }

    private function recipe(string $url, PlacementType $type = PlacementType::Video): array
    {
        $placement = new Placement(['type' => $type, 'format_settings' => []]);
        $placement->setRelation('sizes', new Collection([new PlacementSize([
            'size_type' => 'FIXED', 'width' => 320, 'height' => 180,
            'device' => PlacementDevice::All, 'is_active' => true,
            'min_viewport_width' => 0, 'min_viewport_height' => 0,
        ])]));
        $demand = new DemandPlacement;
        $demand->id = 'vast-transport-test';
        $demand->setRelation('placement', $placement);
        $connector = (new ReflectionClass(CustomThirdPartyTagConnector::class))->newInstanceWithoutConstructor();
        return (new ReflectionMethod($connector, 'vastRecipe'))->invoke($connector, ['url' => $url], ['render_timeout_ms' => 15_000], $demand);
    }
}
