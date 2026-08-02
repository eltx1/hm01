<?php

namespace Tests\Unit;

require_once __DIR__.'/../fixtures/Gam/FakeSoapTypes.php';

use App\Services\Gam\GamSoapPayloadHydrator;
use App\Services\Gam\GamSoapVersionResolver;
use Tests\Fixtures\Gam\FakeCreativeService;
use Tests\Fixtures\Gam\Money;
use Tests\Fixtures\Gam\ThirdPartyCreative;
use Tests\TestCase;

class GamSoapPayloadHydratorTest extends TestCase
{
    public function test_hydrates_version_neutral_nested_payloads_from_generated_method_metadata(): void
    {
        $arguments = (new GamSoapPayloadHydrator)->arguments(
            new FakeCreativeService,
            'createCreatives',
            ['creatives' => [[
                '__type' => 'ThirdPartyCreative',
                'name' => 'Horus creative',
                'costPerUnit' => ['currencyCode' => 'USD', 'microAmount' => 2500000],
            ]]],
            'Tests\\Fixtures\\Gam',
        );

        $this->assertInstanceOf(ThirdPartyCreative::class, $arguments[0][0]);
        $this->assertInstanceOf(Money::class, $arguments[0][0]->costPerUnit);
        $this->assertSame(2500000, $arguments[0][0]->costPerUnit->microAmount);
    }

    public function test_current_official_library_accepts_horus_line_item_and_creative_shapes(): void
    {
        $versions = new GamSoapVersionResolver;
        $namespace = $versions->namespaceFor($versions->resolve());
        $hydrator = new GamSoapPayloadHydrator;

        $lineItem = $hydrator->object('LineItem', [
            'name' => 'Horus Direct', 'orderId' => '77', 'lineItemType' => 'STANDARD',
            'priority' => 8, 'costType' => 'CPM',
            'costPerUnit' => ['currencyCode' => 'USD', 'microAmount' => 2500000],
            'primaryGoal' => ['goalType' => 'LIFETIME', 'unitType' => 'IMPRESSIONS', 'units' => 1000],
            'creativePlaceholders' => [['size' => ['width' => 300, 'height' => 250, 'isAspectRatio' => false]]],
            'targeting' => ['inventoryTargeting' => ['targetedAdUnits' => [['adUnitId' => '42', 'includeDescendants' => false]]]],
            'startDateTime' => '2026-08-02T12:00:00+00:00',
            'endDateTime' => '2026-08-03T12:00:00+00:00',
            'unlimitedEndDateTime' => false,
        ], $namespace);
        $creative = $hydrator->object('ThirdPartyCreative', [
            'advertiserId' => '12', 'name' => 'Universal Prebid',
            'size' => ['width' => 1, 'height' => 1, 'isAspectRatio' => false],
            'snippet' => '<script>window.renderAd()</script>', 'isSafeFrameCompatible' => true,
        ], $namespace);
        $image = $hydrator->object('ImageCreative', [
            'advertiserId' => '12', 'name' => 'Image', 'destinationUrl' => 'https://example.test',
            'size' => ['width' => 300, 'height' => 250, 'isAspectRatio' => false],
            'primaryImageAsset' => ['fileName' => 'creative.png', 'assetByteArray' => base64_encode("\x89PNG\r\n")],
        ], $namespace);

        $this->assertSame($namespace.'\\LineItem', $lineItem::class);
        $this->assertSame($namespace.'\\DateTime', $lineItem->getStartDateTime()::class);
        $this->assertSame('UTC', $lineItem->getStartDateTime()->getTimeZoneId());
        $this->assertSame($namespace.'\\ThirdPartyCreative', $creative::class);
        $this->assertSame("\x89PNG\r\n", $image->getPrimaryImageAsset()->getAssetByteArray());
    }
}
