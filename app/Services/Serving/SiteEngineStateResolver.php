<?php

namespace App\Services\Serving;

use App\Enums\PrebidConfiguredMode;
use App\Enums\PrebidDeliveryMode;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\PrebidSetting;
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
        $site->loadMissing(['siteConfig', 'servingSettings']);
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

        $configuredMode = $site->servingSettings?->prebid_configured_mode ?? PrebidConfiguredMode::Auto;
        if (! $configuredMode instanceof PrebidConfiguredMode) {
            $configuredMode = PrebidConfiguredMode::tryFrom((string) $configuredMode) ?? PrebidConfiguredMode::Auto;
        }

        // A bridge requires a real resolved connection. The legacy network-code
        // fallback can keep existing GAM display delivery compatible, but it is
        // not enough to manufacture a Prebid-to-GAM control-plane relationship.
        $gamBridgeEligible = $gamEnabled && $connection !== null && $connection->is_enabled && ! $gamControlDisabled;
        $standaloneProfileConfigured = PrebidSetting::withoutGlobalScopes()
            ->where('scope', PrebidSetting::SCOPE_SITE_STANDALONE)
            ->where('site_id', $site->id)
            ->where('enabled', true)
            ->exists();

        $prebidDeliveryMode = match ($configuredMode) {
            PrebidConfiguredMode::GamBridge => PrebidDeliveryMode::GamBridge,
            PrebidConfiguredMode::Standalone => PrebidDeliveryMode::Standalone,
            PrebidConfiguredMode::Auto => match (true) {
                $gamBridgeEligible => PrebidDeliveryMode::GamBridge,
                $standaloneProfileConfigured => PrebidDeliveryMode::Standalone,
                $site->serving_mode === ServingMode::HorusDirect => PrebidDeliveryMode::Standalone,
                default => PrebidDeliveryMode::GamBridge,
            },
        };

        $prebidControlDisabled = $this->controls->disabledForSite('PREBID', $site->id);
        $prebidModeSupported = $prebidDeliveryMode === PrebidDeliveryMode::Standalone || $gamRequired;
        $prebidPathAvailable = $prebidDeliveryMode === PrebidDeliveryMode::Standalone || $gamBridgeEligible;
        $prebidEnabled = $masterServingEnabled
            && $prebidModeSupported
            && (bool) $site->prebid_enabled
            && ! $prebidControlDisabled
            && $prebidPathAvailable;
        $prebidReason = match (true) {
            ! $masterServingEnabled => 'MASTER_SERVING_DISABLED',
            ! $prebidModeSupported => 'UNSUPPORTED_SERVING_MODE',
            ! $site->prebid_enabled => 'SITE_PREBID_DISABLED',
            $prebidControlDisabled => 'PREBID_CONTROL_DISABLED',
            $prebidDeliveryMode === PrebidDeliveryMode::GamBridge && ! $gamBridgeEligible => 'GAM_BRIDGE_CONNECTION_REQUIRED',
            $configuredMode === PrebidConfiguredMode::Auto && $prebidDeliveryMode === PrebidDeliveryMode::Standalone && ! $standaloneProfileConfigured => 'STANDALONE_PROFILE_REQUIRED',
            default => 'ENABLED',
        };

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
            prebidConfiguredMode: $configuredMode,
            prebidDeliveryMode: $prebidDeliveryMode,
            prebidReason: $prebidReason,
            directJsEnabled: $directJsEnabled,
            directJsReason: $directJsReason,
        );
    }
}
