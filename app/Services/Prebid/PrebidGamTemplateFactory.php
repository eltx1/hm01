<?php

namespace App\Services\Prebid;

use App\Models\GamConnection;
use App\Models\PrebidGamTemplate;

final class PrebidGamTemplateFactory
{
    public function ensureForConnection(GamConnection $connection): PrebidGamTemplate
    {
        return PrebidGamTemplate::withoutGlobalScopes()->firstOrCreate(
            [
                'gam_connection_id' => $connection->id,
                'name' => 'Default Prebid Setup',
            ],
            [
                'organization_id' => $connection->organization_id,
                'mode' => 'TOP_PRICE',
                'advertiser_name' => config('prebid.setup.advertiser_name'),
                'order_name_prefix' => config('prebid.setup.order_prefix'),
                'targeting_keys' => config('prebid.setup.targeting_keys'),
                'universal_creative_template' => config('prebid.setup.universal_creative'),
                'line_item_type' => 'PRICE_PRIORITY',
                'line_item_priority' => config('prebid.setup.line_item_priority', 12),
                'currency' => data_get($connection->configuration, 'currency', config('prebid.currency', 'USD')),
                'status' => 'ACTIVE',
                'version' => 1,
                'settings' => [
                    'trafficker_id' => data_get($connection->configuration, 'trafficker_id'),
                    'root_ad_unit_id' => data_get($connection->configuration, 'root_ad_unit_id'),
                    'delivery_rate_type' => 'EVENLY',
                    'cost_type' => 'CPM',
                ],
            ],
        );
    }
}
