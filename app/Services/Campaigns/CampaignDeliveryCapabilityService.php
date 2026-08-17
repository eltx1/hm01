<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignDeliveryCapabilityStatus;
use App\Enums\CampaignStatus;
use App\Enums\GamConnectionType;
use App\Enums\GamHealthStatus;
use App\Enums\NotificationCategory;
use App\Enums\NotificationSeverity;
use App\Enums\ServingMode;
use App\Models\Campaign;
use App\Models\GamConnection;
use App\Models\Site;
use App\Services\Gam\GamConnectionResolver;
use App\Services\Gam\GamConnectorManager;
use App\Services\Notifications\HorusNotificationService;
use App\Services\Operations\PlatformControlService;
use App\Services\Settings\GlobalSettingsService;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CampaignDeliveryCapabilityService
{
    public function __construct(
        private readonly GlobalSettingsService $settings,
        private readonly GamConnectionResolver $connections,
        private readonly GamConnectorManager $connectors,
        private readonly PlatformControlService $controls,
        private readonly CampaignNetworkPlanner $planner,
        private readonly HorusNotificationService $notifications,
    ) {
    }

    public function featureEnabled(): bool
    {
        return (bool) $this->settings->get('advertiser_campaigns.enabled');
    }

    public function evaluate(Campaign $campaign, bool $allowPendingCreativeReview = false): CampaignDeliveryCapabilityResult
    {
        if (! $this->featureEnabled()) {
            return $this->blocked(
                CampaignDeliveryCapabilityStatus::CampaignFeatureDisabled,
                'CAMPAIGN_FEATURE_DISABLED',
                'Advertiser Campaigns are disabled at the Horus product level.',
            );
        }

        // Capability answers what can be delivered RIGHT NOW. Force-refresh all
        // delivery-critical relationships rather than trusting an earlier loaded
        // relation on a long-lived model instance after an operational change.
        $campaign->load([
            'sites.site.gamConnection',
            'placements.placement',
            'creatives.files',
            'budget',
            'targets',
            'networkInstances.connection',
        ]);

        $campaignSites = $campaign->sites->where('is_active', true)->values();
        if ($campaignSites->isEmpty()) {
            return $this->blocked(
                CampaignDeliveryCapabilityStatus::TargetInventoryUnavailable,
                'NO_ACTIVE_SITES',
                'The campaign has no active target websites.',
            );
        }

        $networkRows = [];
        $expectedConnectionIds = [];
        foreach ($campaignSites as $campaignSite) {
            $site = $campaignSite->site;
            if (! $site) {
                return $this->blocked(
                    CampaignDeliveryCapabilityStatus::TargetInventoryUnavailable,
                    'TARGET_SITE_MISSING',
                    'A selected campaign website is no longer available.',
                    siteId: $campaignSite->site_id,
                );
            }

            if (! in_array($site->serving_mode, [ServingMode::HorusGam, ServingMode::McmPartnerGam, ServingMode::PublisherGam], true)) {
                return $this->blocked(
                    CampaignDeliveryCapabilityStatus::NoGamBackend,
                    'SITE_HAS_NO_GAM_DELIVERY_BACKEND',
                    'The selected website does not use a GAM delivery backend for Direct Advertiser campaigns.',
                    siteId: $site->id,
                );
            }

            $connection = $this->resolveSelectedConnection($site);
            if (! $connection) {
                $disabled = $this->candidateIncludingDisabled($site);
                if ($disabled && ! $disabled->is_enabled) {
                    return $this->blocked(
                        CampaignDeliveryCapabilityStatus::GamConnectionDisabled,
                        'GAM_CONNECTION_DISABLED',
                        'The selected GAM delivery connection is disabled.',
                        siteId: $site->id,
                        connectionId: $disabled->id,
                    );
                }

                return $this->blocked(
                    CampaignDeliveryCapabilityStatus::NoGamBackend,
                    'NO_ELIGIBLE_GAM_CONNECTION',
                    'No eligible GAM delivery connection can be resolved for the selected website.',
                    siteId: $site->id,
                );
            }

            if (! $this->validDeliveryContext($site, $connection)) {
                return $this->blocked(
                    CampaignDeliveryCapabilityStatus::ConfigurationIncomplete,
                    'GAM_CONNECTION_CONTEXT_MISMATCH',
                    'The selected GAM connection does not belong to the website delivery context.',
                    siteId: $site->id,
                    connectionId: $connection->id,
                );
            }

            if (in_array($connection->health_status, [GamHealthStatus::Failed, GamHealthStatus::Disabled], true)) {
                return $this->blocked(
                    $connection->health_status === GamHealthStatus::Disabled
                        ? CampaignDeliveryCapabilityStatus::GamConnectionDisabled
                        : CampaignDeliveryCapabilityStatus::GamConnectionUnhealthy,
                    $connection->health_status === GamHealthStatus::Disabled ? 'GAM_CONNECTION_DISABLED' : 'GAM_CONNECTION_UNHEALTHY',
                    'The selected GAM connection is not healthy enough for new campaign delivery.',
                    siteId: $site->id,
                    connectionId: $connection->id,
                );
            }

            if (blank($connection->network_code) || blank($connection->driver)) {
                return $this->blocked(
                    CampaignDeliveryCapabilityStatus::ConfigurationIncomplete,
                    'GAM_ROOT_CONFIGURATION_INCOMPLETE',
                    'The selected GAM connection is missing required network configuration.',
                    siteId: $site->id,
                    connectionId: $connection->id,
                );
            }

            if ($this->controls->disabledForSite('GAM', $site->id, $connection->id)
                || $this->controls->disabledForSite('AD_SERVING', $site->id, $connection->id)) {
                return $this->blocked(
                    CampaignDeliveryCapabilityStatus::GamOperationallyDisabled,
                    'GAM_OPERATIONALLY_DISABLED',
                    'Operational controls currently prohibit new GAM-backed campaign delivery.',
                    siteId: $site->id,
                    connectionId: $connection->id,
                );
            }

            try {
                $this->connectors->for($connection);
            } catch (Throwable) {
                return $this->blocked(
                    CampaignDeliveryCapabilityStatus::ConfigurationIncomplete,
                    'GAM_CONNECTOR_UNRESOLVABLE',
                    'The selected GAM connector cannot be resolved from current configuration.',
                    siteId: $site->id,
                    connectionId: $connection->id,
                );
            }

            $expectedConnectionIds[] = $connection->id;
            $networkRows[$connection->id] = [
                'connection_id' => $connection->id,
                'connection_name' => $connection->name,
                'network_type' => $connection->type->value,
                'network_code' => $connection->network_code,
                'health_status' => $connection->health_status->value,
                'site_ids' => array_values(array_unique(array_merge($networkRows[$connection->id]['site_ids'] ?? [], [$site->id]))),
            ];
        }

        try {
            $preview = $this->planner->preview($campaign);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->implode(' ');
            return $this->plannerBlocker($message, array_values($networkRows));
        } catch (Throwable) {
            return new CampaignDeliveryCapabilityResult(
                CampaignDeliveryCapabilityStatus::ConfigurationIncomplete,
                [['code' => 'CAMPAIGN_PLANNING_FAILED', 'message' => 'The campaign delivery plan cannot be produced from current configuration.']],
                array_values($networkRows),
            );
        }

        $plannedConnectionIds = collect($preview['plans'] ?? [])->pluck('connectionId')->filter()->unique()->sort()->values()->all();
        $expectedConnectionIds = collect($expectedConnectionIds)->unique()->sort()->values()->all();
        if ($plannedConnectionIds !== $expectedConnectionIds) {
            return new CampaignDeliveryCapabilityResult(
                CampaignDeliveryCapabilityStatus::ConfigurationIncomplete,
                [['code' => 'CAMPAIGN_NETWORK_ROUTING_MISMATCH', 'message' => 'The campaign network plan does not match its selected website delivery connections.']],
                array_values($networkRows),
            );
        }

        $issues = array_values(array_unique($preview['issues'] ?? []));
        if ($allowPendingCreativeReview) {
            $issues = array_values(array_filter(
                $issues,
                fn (string $issue): bool => ! str_contains(strtolower($issue), 'approved active creative'),
            ));
        }
        if ($issues !== []) {
            return $this->plannerBlocker(implode(' ', $issues), array_values($networkRows), $issues);
        }

        return new CampaignDeliveryCapabilityResult(
            CampaignDeliveryCapabilityStatus::Available,
            [],
            array_values($networkRows),
        );
    }

    public function requireAvailable(Campaign $campaign, string $transition = 'delivery'): CampaignDeliveryCapabilityResult
    {
        $result = $this->evaluate($campaign, $transition === 'submission');
        if ($result->available()) {
            return $result;
        }

        $this->warnIfUnavailable($campaign, $result);
        throw ValidationException::withMessages([
            'delivery_capability' => 'Campaign delivery is currently unavailable. Save or keep the campaign as a draft and contact Horus Media if delivery is required.',
        ]);
    }

    public function warnIfUnavailable(Campaign $campaign, ?CampaignDeliveryCapabilityResult $result = null): int
    {
        if (! in_array($campaign->status, [CampaignStatus::Approved, CampaignStatus::Scheduled, CampaignStatus::Active], true)) {
            return 0;
        }

        $result ??= $this->evaluate($campaign);
        if ($result->available()) {
            return 0;
        }

        $recipients = $this->notifications->horusRecipients('campaigns.deploy');
        return $this->notifications->notify($recipients, [
            'category' => NotificationCategory::Operations,
            'type' => 'campaign.delivery_backend_unavailable',
            'severity' => NotificationSeverity::Warning,
            'title' => 'Campaign delivery backend unavailable',
            'message' => 'Campaign '.$campaign->public_key.' requires Admin attention before new or resumed delivery. Capability: '.$result->status->value.'.',
            'event_key' => 'campaign-delivery-capability:'.$campaign->id.':'.$result->status->value,
            'related_type' => Campaign::class,
            'related_id' => $campaign->id,
        ]);
    }

    private function resolveSelectedConnection(Site $site): ?GamConnection
    {
        if ($site->gam_connection_id) {
            return GamConnection::withoutGlobalScopes()
                ->whereKey($site->gam_connection_id)
                ->where('is_enabled', true)
                ->first();
        }

        return $this->connections->resolve($site);
    }

    private function candidateIncludingDisabled(Site $site): ?GamConnection
    {
        if ($site->gam_connection_id) {
            return GamConnection::withoutGlobalScopes()->find($site->gam_connection_id);
        }

        return match ($site->serving_mode) {
            ServingMode::HorusGam => GamConnection::withoutGlobalScopes()
                ->where('type', GamConnectionType::HorusGam->value)
                ->where('is_primary', true)
                ->first(),
            ServingMode::McmPartnerGam => GamConnection::withoutGlobalScopes()
                ->where('type', GamConnectionType::McmPartnerGam->value)
                ->orderByDesc('is_enabled')
                ->orderByDesc('last_successful_sync_at')
                ->first(),
            ServingMode::PublisherGam => GamConnection::withoutGlobalScopes()
                ->where('type', GamConnectionType::PublisherGam->value)
                ->where('organization_id', $site->organization_id)
                ->orderByDesc('is_enabled')
                ->orderByDesc('last_successful_sync_at')
                ->first(),
            default => null,
        };
    }

    private function validDeliveryContext(Site $site, GamConnection $connection): bool
    {
        return match ($site->serving_mode) {
            ServingMode::HorusGam => $connection->type === GamConnectionType::HorusGam,
            ServingMode::McmPartnerGam => $connection->type === GamConnectionType::McmPartnerGam,
            ServingMode::PublisherGam => $connection->type === GamConnectionType::PublisherGam
                && $connection->organization_id === $site->organization_id,
            default => false,
        };
    }

    private function plannerBlocker(string $message, array $networks = [], array $issues = []): CampaignDeliveryCapabilityResult
    {
        $lower = strtolower($message);
        $status = match (true) {
            str_contains($lower, 'no active placements'), str_contains($lower, 'no active websites')
                => CampaignDeliveryCapabilityStatus::TargetInventoryUnavailable,
            str_contains($lower, 'synchronize') && str_contains($lower, 'ad unit'),
            str_contains($lower, 'no gam location id mapping'),
            str_contains($lower, 'no gam device-category id mapping')
                => CampaignDeliveryCapabilityStatus::RemoteMappingIncomplete,
            default => CampaignDeliveryCapabilityStatus::ConfigurationIncomplete,
        };

        return new CampaignDeliveryCapabilityResult(
            $status,
            [['code' => $status->value, 'message' => $issues !== [] ? implode(' ', $issues) : $message]],
            $networks,
        );
    }

    private function blocked(
        CampaignDeliveryCapabilityStatus $status,
        string $code,
        string $message,
        ?string $siteId = null,
        ?string $connectionId = null,
    ): CampaignDeliveryCapabilityResult {
        return new CampaignDeliveryCapabilityResult($status, [array_filter([
            'code' => $code,
            'message' => $message,
            'site_id' => $siteId,
            'connection_id' => $connectionId,
        ], fn ($value) => $value !== null)]);
    }
}
