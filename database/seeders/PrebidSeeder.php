<?php

namespace Database\Seeders;

use App\Enums\PrebidBuildStatus;
use App\Models\PrebidAdapter;
use App\Models\PrebidBidder;
use App\Models\PrebidBuild;
use Illuminate\Database\Seeder;

class PrebidSeeder extends Seeder
{
    public function run(): void
    {
        $build = PrebidBuild::withoutGlobalScopes()->updateOrCreate(
            ['version' => config('prebid.default_build_version')],
            [
                'organization_id' => null,
                'name' => 'Horus Media Default Browser Build',
                'prebid_version' => config('prebid.version'),
                'source_repository' => config('prebid.source_repository'),
                'source_reference' => config('prebid.source_reference'),
                'modules' => config('prebid.default_modules'),
                'source_path' => 'prebid-builds/horus-default.json',
                'asset_path' => config('prebid.default_asset_path'),
                'minified_path' => config('prebid.default_minified_path'),
                'manifest_path' => config('prebid.default_manifest_path'),
                'status' => PrebidBuildStatus::Ready,
                'is_active' => true,
                'notes' => 'Compiled by scripts/build-prebid.mjs during release creation; no Node.js runtime is required in production.',
            ],
        );

        PrebidBuild::withoutGlobalScopes()->whereKeyNot($build->id)->update(['is_active' => false]);

        foreach ($this->registry() as $definition) {
            $adapter = PrebidAdapter::query()->updateOrCreate(
                ['bidder_code' => $definition['bidder_code']],
                [
                    'module_name' => $definition['module_name'],
                    'display_name' => $definition['display_name'],
                    'required_public_parameters' => $definition['required'],
                    'optional_public_parameters' => $definition['optional'],
                    'supported_media_types' => $definition['media_types'],
                    'supported_sizes' => $definition['sizes'],
                    'documentation_url' => $definition['documentation_url'],
                    'is_enabled' => true,
                    'verified_at' => now(),
                ],
            );

            PrebidBidder::withoutGlobalScopes()->updateOrCreate(
                ['code' => $definition['bidder_code']],
                [
                    'organization_id' => null,
                    'prebid_adapter_id' => $adapter->id,
                    'display_name' => $definition['display_name'],
                    'is_enabled' => true,
                    'defaults' => [],
                ],
            );
        }
    }

    private function registry(): array
    {
        $bannerSizes = [[300, 250], [300, 600], [320, 50], [320, 100], [728, 90], [970, 90], [970, 250]];

        return [
            [
                'bidder_code' => 'appnexus', 'module_name' => 'appnexusBidAdapter', 'display_name' => 'AppNexus / Xandr',
                'required' => ['placementId'], 'optional' => ['member', 'invCode', 'keywords', 'user'],
                'media_types' => ['banner'], 'sizes' => $bannerSizes,
                'documentation_url' => 'https://docs.prebid.org/dev-docs/bidders/appnexus.html',
            ],
            [
                'bidder_code' => 'ix', 'module_name' => 'ixBidAdapter', 'display_name' => 'Index Exchange',
                'required' => ['siteId'], 'optional' => ['size', 'bidFloor', 'bidFloorCur'],
                'media_types' => ['banner'], 'sizes' => $bannerSizes,
                'documentation_url' => 'https://docs.prebid.org/dev-docs/bidders/ix.html',
            ],
            [
                'bidder_code' => 'openx', 'module_name' => 'openxBidAdapter', 'display_name' => 'OpenX',
                'required' => ['unit'], 'optional' => ['delDomain', 'customParams', 'platform'],
                'media_types' => ['banner'], 'sizes' => $bannerSizes,
                'documentation_url' => 'https://docs.prebid.org/dev-docs/bidders/openx.html',
            ],
            [
                'bidder_code' => 'pubmatic', 'module_name' => 'pubmaticBidAdapter', 'display_name' => 'PubMatic',
                'required' => ['publisherId', 'adSlot'], 'optional' => ['pmzoneid', 'kadfloor', 'currency'],
                'media_types' => ['banner'], 'sizes' => $bannerSizes,
                'documentation_url' => 'https://docs.prebid.org/dev-docs/bidders/pubmatic.html',
            ],
            [
                'bidder_code' => 'rubicon', 'module_name' => 'rubiconBidAdapter', 'display_name' => 'Magnite / Rubicon',
                'required' => ['accountId', 'siteId', 'zoneId'], 'optional' => ['position', 'keywords', 'inventory'],
                'media_types' => ['banner'], 'sizes' => $bannerSizes,
                'documentation_url' => 'https://docs.prebid.org/dev-docs/bidders/rubicon.html',
            ],
        ];
    }
}
