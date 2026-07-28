<?php

namespace Database\Seeders;

use App\Models\PrebidAdapter;
use App\Models\PrebidBuild;
use Illuminate\Database\Seeder;

class PrebidSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->adapters() as $adapter) {
            PrebidAdapter::query()->updateOrCreate(
                ['bidder_code' => $adapter['bidder_code']],
                $adapter,
            );
        }

        $version = (string) config('prebid.version', '11.26.0');
        $asset = public_path("assets/prebid/prebid-{$version}.js");
        $minified = public_path("assets/prebid/prebid-{$version}.min.js");
        $manifest = public_path("assets/prebid/prebid-{$version}.manifest.json");
        $ready = is_file($asset) && is_file($minified) && is_file($manifest);

        PrebidBuild::query()->updateOrCreate(
            ['version' => $version],
            [
                'name' => "Horus Prebid {$version}",
                'source_ref' => $version,
                'source_url' => config('prebid.source_repository'),
                'asset_path' => "assets/prebid/prebid-{$version}.js",
                'minified_path' => "assets/prebid/prebid-{$version}.min.js",
                'manifest_path' => "assets/prebid/prebid-{$version}.manifest.json",
                'checksum' => $ready ? hash_file('sha256', $minified) : null,
                'modules' => config('prebid.modules'),
                'adapters' => ['pubmatic', 'rubicon', 'openx'],
                'status' => $ready ? 'READY' : 'PENDING',
                'is_active' => $ready,
                'built_at' => $ready ? now() : null,
            ],
        );
    }

    private function adapters(): array
    {
        return [
            [
                'bidder_code' => 'pubmatic',
                'adapter_name' => 'PubMatic',
                'required_public_parameters' => [['name' => 'publisherId', 'type' => 'string']],
                'optional_public_parameters' => [
                    ['name' => 'adSlot', 'type' => 'string'],
                    ['name' => 'dctr', 'type' => 'string'],
                    ['name' => 'deals', 'type' => 'array'],
                    ['name' => 'outstreamAU', 'type' => 'string'],
                ],
                'supported_media_types' => ['banner', 'video', 'native'],
                'supported_sizes' => ['all'],
                'documentation_url' => 'https://docs.prebid.org/dev-docs/bidders/pubmatic.html',
                'enabled' => true,
                'metadata' => [
                    'module' => 'pubmaticBidAdapter',
                    'publisher_parameter' => 'publisherId',
                    'placement_parameter' => 'adSlot',
                ],
            ],
            [
                'bidder_code' => 'rubicon',
                'adapter_name' => 'Magnite / Rubicon',
                'required_public_parameters' => [
                    ['name' => 'accountId', 'type' => 'integer'],
                    ['name' => 'siteId', 'type' => 'integer'],
                    ['name' => 'zoneId', 'type' => 'integer'],
                ],
                'optional_public_parameters' => [
                    ['name' => 'position', 'type' => 'string'],
                    ['name' => 'floor', 'type' => 'number'],
                    ['name' => 'keywords', 'type' => 'array'],
                    ['name' => 'video', 'type' => 'object'],
                ],
                'supported_media_types' => ['banner', 'video', 'native'],
                'supported_sizes' => ['all'],
                'documentation_url' => 'https://docs.prebid.org/dev-docs/bidders/rubicon.html',
                'enabled' => true,
                'metadata' => [
                    'module' => 'rubiconBidAdapter',
                    'publisher_parameter' => 'accountId',
                    'placement_parameter' => 'zoneId',
                ],
            ],
            [
                'bidder_code' => 'openx',
                'adapter_name' => 'OpenX',
                'required_public_parameters' => [
                    ['name' => 'unit', 'type' => 'string'],
                    ['name' => 'delDomain', 'type' => 'string'],
                ],
                'optional_public_parameters' => [
                    ['name' => 'customParams', 'type' => 'object'],
                    ['name' => 'doNotTrack', 'type' => 'boolean'],
                    ['name' => 'coppa', 'type' => 'boolean'],
                    ['name' => 'video', 'type' => 'object'],
                ],
                'supported_media_types' => ['banner', 'video', 'native'],
                'supported_sizes' => ['all'],
                'documentation_url' => 'https://docs.prebid.org/dev-docs/bidders/openx.html',
                'enabled' => true,
                'metadata' => [
                    'module' => 'openxBidAdapter',
                    'placement_parameter' => 'unit',
                ],
            ],
        ];
    }
}
