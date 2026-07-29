<?php

namespace App\Services\Prebid;

use App\Models\GamConnection;
use App\Models\PrebidGamRemoteObject;
use App\Models\PrebidGamTemplate;
use App\Models\PrebidPriceBucket;
use RuntimeException;

final class PrebidGamSetupPlanner
{
    public function __construct(private readonly PrebidManager $manager)
    {
    }

    public function plan(GamConnection $connection): array
    {
        $settings = $this->manager->settingsFor($connection);
        $template = PrebidGamTemplate::withoutGlobalScopes()
            ->where('gam_connection_id', $connection->id)
            ->where('enabled', true)
            ->firstOrFail();
        $rootAdUnitId = data_get($connection->configuration, 'root_ad_unit_id');
        if (! filled($rootAdUnitId)) {
            return [
                'networkCode' => $connection->network_code,
                'connectionType' => $connection->type->value,
                'currency' => strtoupper((string) $settings->currency),
                'estimatedObjects' => 0,
                'existingObjects' => 0,
                'pendingObjects' => 0,
                'complete' => false,
                'issues' => ['The selected GAM connection must define configuration.root_ad_unit_id before Prebid setup.'],
                'objects' => [],
            ];
        }

        $prices = $this->prices($connection);
        if ($prices === []) {
            return [
                'networkCode' => $connection->network_code,
                'connectionType' => $connection->type->value,
                'currency' => strtoupper((string) $settings->currency),
                'estimatedObjects' => 0,
                'existingObjects' => 0,
                'pendingObjects' => 0,
                'complete' => false,
                'issues' => ['At least one enabled valid Prebid price bucket is required.'],
                'objects' => [],
            ];
        }
        $currency = strtoupper((string) $settings->currency);
        $objects = [];

        $objects[] = $this->object('advertiser', 'prebid-advertiser', 'createCompany', [
            'name' => $template->advertiser_name,
            'type' => 'ADVERTISER',
        ]);

        foreach ([
            'hb_pb' => ['displayName' => 'Prebid price bucket', 'type' => 'PREDEFINED'],
            'hb_bidder' => ['displayName' => 'Prebid bidder', 'type' => 'FREEFORM'],
            'hb_adid' => ['displayName' => 'Prebid ad id', 'type' => 'FREEFORM'],
            'hb_size' => ['displayName' => 'Prebid creative size', 'type' => 'FREEFORM'],
            'hb_format' => ['displayName' => 'Prebid media format', 'type' => 'FREEFORM'],
        ] as $name => $key) {
            $objects[] = $this->object('targeting_key', 'targeting-key-'.$name, 'createCustomTargetingKey', [
                'name' => $name,
                'displayName' => $key['displayName'],
                'type' => $key['type'],
                'reportableType' => 'ON',
            ]);
        }

        foreach ($prices as $price) {
            $objects[] = $this->object('targeting_value', 'targeting-value-'.$price, 'createCustomTargetingValue', [
                'customTargetingKeyId' => '@targeting-key-hb_pb',
                'name' => $price,
                'displayName' => $price,
                'matchType' => 'EXACT',
            ]);
        }

        $objects[] = $this->object('order', 'prebid-order', 'createOrder', [
            'name' => $template->order_name_prefix.' '.$currency,
            'advertiserId' => '@prebid-advertiser',
            'status' => 'DRAFT',
        ]);

        $objects[] = $this->object('creative', 'universal-creative', 'createCreative', [
            '__type' => 'ThirdPartyCreative',
            'name' => $template->creative_name,
            'advertiserId' => '@prebid-advertiser',
            'size' => ['width' => 1, 'height' => 1, 'isAspectRatio' => false],
            'snippet' => $template->creative_snippet,
            'isSafeFrameCompatible' => true,
        ]);

        foreach ($prices as $price) {
            $lineKey = 'line-item-'.$currency.'-'.$price;
            $objects[] = $this->object('line_item', $lineKey, 'createLineItem', [
                'name' => str_replace(['{{currency}}', '{{price}}'], [$currency, $price], $template->line_item_name_template),
                'orderId' => '@prebid-order',
                'lineItemType' => 'PRICE_PRIORITY',
                'priority' => 12,
                'costType' => 'CPM',
                'costPerUnit' => ['currencyCode' => $currency, 'microAmount' => (int) round(((float) $price) * 1_000_000)],
                'creativePlaceholders' => [[
                    'size' => ['width' => 1, 'height' => 1, 'isAspectRatio' => false],
                    'creativeSizeType' => 'IGNORED',
                ]],
                'targeting' => [
                    'inventoryTargeting' => ['targetedAdUnits' => [['adUnitId' => (string) $rootAdUnitId, 'includeDescendants' => true]]],
                    'customTargeting' => [
                        '__type' => 'CustomCriteria',
                        'keyId' => '@targeting-key-hb_pb',
                        'operator' => 'IS',
                        'valueIds' => ['@targeting-value-'.$price],
                    ],
                ],
                'unlimitedEndDateTime' => true,
            ]);
            $objects[] = $this->object('association', 'creative-association-'.$currency.'-'.$price, 'associateCreative', [
                'lineItemId' => '@'.$lineKey,
                'creativeId' => '@universal-creative',
            ]);
        }

        $existing = PrebidGamRemoteObject::withoutGlobalScopes()
            ->where('gam_connection_id', $connection->id)
            ->pluck('idempotency_key')
            ->all();
        $pending = array_values(array_filter($objects, fn (array $object) => ! in_array($object['key'], $existing, true)));

        return [
            'networkCode' => $connection->network_code,
            'connectionType' => $connection->type->value,
            'currency' => $currency,
            'estimatedObjects' => count($objects),
            'existingObjects' => count($objects) - count($pending),
            'pendingObjects' => count($pending),
            'complete' => $pending === [],
            'issues' => [],
            'objects' => $objects,
        ];
    }

    private function prices(GamConnection $connection): array
    {
        $values = [];
        $buckets = PrebidPriceBucket::withoutGlobalScopes()
            ->where('gam_connection_id', $connection->id)
            ->where('enabled', true)
            ->orderBy('sort_order')
            ->get();

        foreach ($buckets as $bucket) {
            $minimum = (float) $bucket->minimum;
            $maximum = (float) $bucket->maximum;
            $increment = (float) $bucket->increment;
            if ($increment <= 0 || $maximum <= $minimum) {
                continue;
            }
            for ($value = $minimum; $value < $maximum; $value += $increment) {
                $values[] = number_format($value, (int) $bucket->precision, '.', '');
                if (count($values) > 5000) {
                    throw new RuntimeException('Prebid price bucket configuration exceeds the 5,000 value safety limit.');
                }
            }
        }

        return array_values(array_unique($values));
    }

    private function object(string $type, string $key, string $method, array $payload): array
    {
        return [
            'type' => $type,
            'key' => $key,
            'method' => $method,
            'payload' => $payload,
            'payloadHash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        ];
    }
}
