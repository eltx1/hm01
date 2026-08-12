<?php

namespace App\Services\Serving;

use App\Enums\PrebidConfiguredMode;
use App\Enums\PrebidDeliveryMode;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\Gam\GamConnectionResolver;
use App\Services\Operations\PlatformControlService;

final class SiteEngineStateResolver
{
    public function __construct(
        private readonly GamConnectionResolver $connections,
        private readonly PlatformControlService $controls,
    ) {}

    public function resolve(Site $site): ResolvedSiteEngineState
    {
        $site->loadMissing('siteConfig');
        $connection = $this->connections->resolve($site);
        $gamRequired = in_array($site->serving_mode, [
            ServingMode::HorusGam,
            ServingMode::McmPartnerGam,
            ServingMode::PublisherGam,
        ], true);

        $masterDisabled = $this->controls->disabledForSite('AD_SERVING', $site->id, $connection?->id);
        $paused = $site->serving_mode === ServingMode::Paused
            || $site->status === SiteStatus::Suspended
            || (bool) $site->siteConfig?->immediate_pause
            || $masterDisabled;
        $masterServingEnabled = ! $paused;

        $gamControlDisabled = $this->controls->disabledForSite('GAM', $site->id, $connection?->id);
        $legacyNetworkCodeAvailable = $gamRequired && filled($site->current_gam_network_code);
        $gamConnectionAvailable = $connection !== null && $connection->is_enabled;
        $gamEnabled = $masterServingEnabled
            && $gamRequired
            && ($gamConnectionAvailable || $legacyNetworkCodeAvailable)
            && ! $gamControlDisabled;
        $gamReason = match (true) {
            ! $masterServingEnabled => 'MASTER_SERVING_DISABLED',
            ! $gamRequired => 'NOT_REQUIRED_BY_SERVING_MODE',
            $connection !== null && ! $connection->is_enabled && ! $legacyNetworkCodeAvailable => 'GAM_CONNECTION_DISABLED',
            ! $gamConnectionAvailable && ! $legacyNetworkCodeAvailable => 'NO_GAM_CONNECTION',
            $gamControlDisabled => 'GAM_CONTROL_DISABLED',
            ! $gamConnectionAvailable && $legacyNetworkCodeAvailable => 'LEGACY_NETWORK_CODE_FALLBACK',
            default => 'ENABLED',
        };

        // AUTO never reaches the browser. It is resolved here to one of the two
        // concrete runtime delivery modes. A bridge requires a real, enabled,
        // operational GAM connection; a legacy network-code fallback is not
        // sufficient for Prebid-to-GAM setup/targeting.
        $configuredPrebidMode = $site->prebid_delivery_mode ?? PrebidConfiguredMode::Auto;
        $prebidBridgeAvailable = $gamEnabled && $connection !== null && $connection->is_enabled;
        $prebidDeliveryMode = match ($configuredPrebidMode) {
            PrebidConfiguredMode::GamBridge => PrebidDeliveryMode::GamBridge,
            PrebidConfiguredMode::Standalone => PrebidDeliveryMode::Standalone,
            PrebidConfiguredMode::Auto => $prebidBridgeAvailable
                ? PrebidDeliveryMode::GamBridge
                : PrebidDeliveryMode::Standalone,
        };

        $prebidControlDisabled = $this->controls->disabledForSite('PREBID', $site->id);
        $prebidModeSupported = $prebidDeliveryMode === PrebidDeliveryMode::Standalone || $gamRequired;
        $prebidEnabled = $masterServingEnabled
            && $prebidModeSupported
            && (bool) $site->prebid_enabled
            && ! $prebidControlDisabled
            && ($prebidDeliveryMode !== PrebidDeliveryMode::GamBridge || $prebidBridgeAvailable);
        $prebidReason = match (true) {
            ! $masterServingEnabled => 'MASTER_SERVING_DISABLED',
            ! $site->prebid_enabled => 'SITE_PREBID_DISABLED',
            $prebidControlDisabled => 'PREBID_CONTROL_DISABLED',
            ! $prebidModeSupported => 'UNSUPPORTED_SERVING_MODE',
            $prebidDeliveryMode === PrebidDeliveryMode::GamBridge && ! $prebidBridgeAvailable => 'GAM_BRIDGE_CONNECTION_REQUIRED',
            default => 'ENABLED',
        };

        // NATIVE_DEMAND remains a backward-compatible alias for the Direct JS
        // engine control during the schema-v2/v3 compatibility period.
        $directJsControlDisabled = $this->controls->disabledForSite('DIRECT_JS', $site->id)
            || $this->controls->disabledForSite('NATIVE_DEMAND', $site->id);
        $directJsEnabled = $masterServingEnabled
            && (bool) $site->native_demand_enabled
            && ! $directJsControlDisabled;
        $directJsReason = match (true) {
            ! $masterServingEnabled => 'MASTER_SERVING_DISABLED',
            ! $site->native_demand_enabled => 'SITE_DIRECT_JS_DISABLED',
            $directJsControlDisabled => 'DIRECT_JS_CONTROL_DISABLED',
            default => 'ENABLED',
        };

        return new ResolvedSiteEngineState(
            masterServingEnabled: $masterServingEnabled,
            paused: $paused,
            gamConnection: $connection,
            gamRequired: $gamRequired,
            gamEnabled: $gamEnabled,
            gamReason: $gamReason,
            prebidEnabled: $prebidEnabled,
            prebidConfiguredMode: $configuredPrebidMode,
            prebidDeliveryMode: $prebidDeliveryMode,
            prebidReason: $prebidReason,
            directJsEnabled: $directJsEnabled,
            directJsReason: $directJsReason,
        );
    }
}
