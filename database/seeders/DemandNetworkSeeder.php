<?php

namespace Database\Seeders;

use App\Enums\DemandIntegrationMode;
use App\Enums\DemandNetworkCode;
use App\Models\DemandNetwork;
use App\Services\Demand\ConfiguredDemandConnector;
use App\Services\Demand\ExoClickConnector;
use App\Services\Demand\MgidConnector;
use App\Services\Demand\OutbrainConnector;
use App\Services\Demand\SpeakolConnector;
use App\Services\Demand\TaboolaConnector;
use Illuminate\Database\Seeder;

class DemandNetworkSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            DemandNetworkCode::Mgid->value => [
                'name' => 'MGID',
                'connector_class' => MgidConnector::class,
                'default_integration_mode' => DemandIntegrationMode::DirectJs,
                'supports_direct_js' => true,
                'supports_gam_creative' => true,
                'supports_gam_line_item' => true,
                'supports_api' => true,
                'capabilities' => ['site_mapping', 'widget_mapping', 'ads_txt', 'api_reports', 'csv_reports'],
            ],
            DemandNetworkCode::Taboola->value => [
                'name' => 'Taboola',
                'connector_class' => TaboolaConnector::class,
                'default_integration_mode' => DemandIntegrationMode::DirectJs,
                'supports_direct_js' => true,
                'supports_gam_creative' => true,
                'supports_gam_line_item' => true,
                'supports_api' => true,
                'capabilities' => ['site_mapping', 'placement_mapping', 'ads_txt', 'api_placeholder', 'csv_reports'],
            ],
            DemandNetworkCode::Speakol->value => [
                'name' => 'Speakol',
                'connector_class' => SpeakolConnector::class,
                'default_integration_mode' => DemandIntegrationMode::DirectJs,
                'supports_direct_js' => true,
                'supports_gam_creative' => true,
                'supports_gam_line_item' => true,
                'supports_api' => true,
                'capabilities' => ['site_mapping', 'widget_mapping', 'ads_txt', 'api_placeholder', 'csv_reports'],
            ],
            DemandNetworkCode::Outbrain->value => [
                'name' => 'Outbrain',
                'connector_class' => OutbrainConnector::class,
                'default_integration_mode' => DemandIntegrationMode::DirectJs,
                'supports_direct_js' => true,
                'supports_gam_creative' => true,
                'supports_gam_line_item' => true,
                'supports_api' => true,
                'capabilities' => ['site_mapping', 'placement_mapping', 'ads_txt', 'api_placeholder', 'csv_reports'],
            ],
            DemandNetworkCode::ExoClick->value => [
                'name' => 'ExoClick',
                'connector_class' => ExoClickConnector::class,
                'default_integration_mode' => DemandIntegrationMode::DirectJs,
                'supports_direct_js' => true,
                'supports_gam_creative' => false,
                'supports_gam_line_item' => false,
                'supports_api' => false,
                'capabilities' => ['placement_mapping', 'configured_tag', 'csv_reports'],
            ],
            DemandNetworkCode::CustomNative->value => [
                'name' => 'Custom Native',
                'connector_class' => ConfiguredDemandConnector::class,
                'default_integration_mode' => DemandIntegrationMode::ManualTag,
                'supports_direct_js' => true,
                'supports_gam_creative' => true,
                'supports_gam_line_item' => true,
                'supports_api' => true,
                'capabilities' => ['configured_tag', 'house_content', 'csv_reports'],
            ],
            DemandNetworkCode::CustomDisplay->value => [
                'name' => 'Custom Display',
                'connector_class' => ConfiguredDemandConnector::class,
                'default_integration_mode' => DemandIntegrationMode::ManualTag,
                'supports_direct_js' => true,
                'supports_gam_creative' => true,
                'supports_gam_line_item' => true,
                'supports_api' => true,
                'capabilities' => ['configured_tag', 'csv_reports'],
            ],
            DemandNetworkCode::CustomThirdPartyTag->value => [
                'name' => 'Custom Third-Party Tag',
                'connector_class' => ConfiguredDemandConnector::class,
                'default_integration_mode' => DemandIntegrationMode::GamThirdPartyCreative,
                'supports_direct_js' => true,
                'supports_gam_creative' => true,
                'supports_gam_line_item' => true,
                'supports_api' => true,
                'capabilities' => ['configured_tag', 'gam_creative', 'csv_reports'],
            ],
        ];

        foreach ($definitions as $code => $definition) {
            DemandNetwork::query()->updateOrCreate(
                ['code' => $code],
                $definition + [
                    'is_enabled' => true,
                    'script_origins' => config('demand.allowed_script_origins.'.$code, []),
                    'metadata' => ['credentials_provided_by_account' => true],
                ],
            );
        }
    }
}
