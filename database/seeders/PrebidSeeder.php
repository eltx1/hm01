<?php

namespace Database\Seeders;

use App\Models\PrebidAdapter;
use App\Models\PrebidBidder;
use App\Models\PrebidBuild;
use Illuminate\Database\Seeder;

class PrebidSeeder extends Seeder
{
    public function run(): void
    {
        $adapters = [
            ['code' => 'msft', 'display_name' => 'Microsoft Monetize', 'module_code' => 'msftBidAdapter', 'publisher_parameter' => null, 'placement_parameter' => 'placement_id', 'required_public_parameters' => ['placement_id'], 'optional_public_parameters' => ['member', 'inv_code', 'keywords'], 'supported_media_types' => ['banner', 'native', 'video'], 'supported_sizes' => [], 'documentation_url' => 'https://docs.prebid.org/dev-docs/bidders/msft.html'],
            ['code' => 'rubicon', 'display_name' => 'Magnite', 'module_code' => 'rubiconBidAdapter', 'publisher_parameter' => 'accountId', 'placement_parameter' => 'zoneId', 'required_public_parameters' => ['accountId', 'siteId', 'zoneId'], 'optional_public_parameters' => ['keywords', 'video'], 'supported_media_types' => ['banner', 'video'], 'supported_sizes' => [], 'documentation_url' => 'https://docs.prebid.org/dev-docs/bidders/rubicon.html'],
            ['code' => 'openx', 'display_name' => 'OpenX', 'module_code' => 'openxBidAdapter', 'publisher_parameter' => 'delDomain', 'placement_parameter' => 'unit', 'required_public_parameters' => ['unit', 'delDomain'], 'optional_public_parameters' => ['customParams'], 'supported_media_types' => ['banner', 'video'], 'supported_sizes' => [], 'documentation_url' => 'https://docs.prebid.org/dev-docs/bidders/openx.html'],
            ['code' => 'pubmatic', 'display_name' => 'PubMatic', 'module_code' => 'pubmaticBidAdapter', 'publisher_parameter' => 'publisherId', 'placement_parameter' => 'adSlot', 'required_public_parameters' => ['publisherId', 'adSlot'], 'optional_public_parameters' => ['kadfloor', 'keywords'], 'supported_media_types' => ['banner', 'video'], 'supported_sizes' => [], 'documentation_url' => 'https://docs.prebid.org/dev-docs/bidders/pubmatic.html'],
            ['code' => 'ix', 'display_name' => 'Index Exchange', 'module_code' => 'ixBidAdapter', 'publisher_parameter' => null, 'placement_parameter' => 'siteId', 'required_public_parameters' => ['siteId'], 'optional_public_parameters' => ['size'], 'supported_media_types' => ['banner', 'video'], 'supported_sizes' => [], 'documentation_url' => 'https://docs.prebid.org/dev-docs/bidders/ix.html'],
            ['code' => 'triplelift', 'display_name' => 'TripleLift', 'module_code' => 'tripleliftBidAdapter', 'publisher_parameter' => null, 'placement_parameter' => 'inventoryCode', 'required_public_parameters' => ['inventoryCode'], 'optional_public_parameters' => ['floor'], 'supported_media_types' => ['banner', 'native', 'video'], 'supported_sizes' => [], 'documentation_url' => 'https://docs.prebid.org/dev-docs/bidders/triplelift.html'],
            ['code' => 'onetag', 'display_name' => 'OneTag', 'module_code' => 'onetagBidAdapter', 'publisher_parameter' => 'pubId', 'placement_parameter' => null, 'required_public_parameters' => ['pubId'], 'optional_public_parameters' => ['ext'], 'supported_media_types' => ['banner', 'native', 'video'], 'supported_sizes' => [], 'documentation_url' => 'https://docs.prebid.org/dev-docs/bidders/onetag.html'],
        ];

        foreach ($adapters as $item) {
            $adapter = PrebidAdapter::query()->updateOrCreate(['code' => $item['code']], $item + ['enabled' => true]);
            PrebidBidder::query()->updateOrCreate(
                ['organization_id' => null, 'code' => $item['code']],
                ['prebid_adapter_id' => $adapter->id, 'display_name' => $item['display_name'], 'enabled' => true],
            );
        }

        PrebidBuild::query()->updateOrCreate(
            ['organization_id' => null, 'name' => 'horus-default', 'version' => config('prebid.version')],
            [
                'file_path' => config('prebid.source_path'),
                'minified_path' => config('prebid.build_path'),
                'modules' => array_values(array_merge(array_column($adapters, 'module_code'), ['consentManagementTcf', 'consentManagementGpp', 'tcfControl', 'gppControl_usnat', 'gppControl_usstates', 'storageControl', 'gptPreAuction', 'validationFpdModule', 'currency'])),
                'is_active' => true,
                'built_at' => now(),
            ],
        );
    }
}
