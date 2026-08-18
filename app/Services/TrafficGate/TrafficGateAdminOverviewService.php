<?php

namespace App\Services\TrafficGate;

use App\Enums\SiteStatus;
use App\Enums\StaticDeliveryStatus;
use App\Enums\TrafficGateAdminReadiness;
use App\Enums\TrafficGateSitePolicy;
use App\Enums\TrafficGateSiteState;
use App\Models\Site;
use App\Models\StaticDeliveryItem;
use App\Services\Operations\PlatformControlService;
use App\Services\Settings\GlobalSettingsService;
use Illuminate\Support\Collection;

final class TrafficGateAdminOverviewService
{
    public function __construct(
        private readonly GlobalSettingsService $settings,
        private readonly TrafficGateConfigurationResolver $resolver,
        private readonly PlatformControlService $controls,
    ) {}

    public function readiness(): TrafficGateAdminReadiness
    {
        $siteKey = trim((string) ($this->settings->get('traffic_gate.site_key') ?? ''));
        if ($siteKey === '' || ! preg_match('/^[A-Za-z0-9_-]{3,255}$/', $siteKey)) {
            return TrafficGateAdminReadiness::SitekeyMissing;
        }

        if (! $this->resolver->validOrigin(trim((string) config('traffic_gate.origin')))) {
            return TrafficGateAdminReadiness::GateOriginInvalid;
        }

        $initial = (int) $this->settings->get('traffic_gate.initial_wait_ms');
        $maximum = (int) $this->settings->get('traffic_gate.max_wait_ms');
        $retry = (int) $this->settings->get('traffic_gate.retry_interval_ms');
        if ($initial < 500 || $initial > 5000
            || $maximum < 2000 || $maximum > 15000
            || $retry < 500 || $retry > 10000
            || $maximum < $initial) {
            return TrafficGateAdminReadiness::InvalidTiming;
        }

        if (! is_file(public_path('traffic-gate/index.html'))
            || ! is_file(public_path('assets/traffic-gate/horus-traffic-gate.js'))) {
            return TrafficGateAdminReadiness::GateAssetNotConfigured;
        }

        return TrafficGateAdminReadiness::Ready;
    }

    /** @return array<string, mixed> */
    public function globalSnapshot(): array
    {
        $readiness = $this->readiness();
        $enabled = (bool) $this->settings->get('traffic_gate.enabled');
        $emergencyDisabled = $this->controls->disabled('PLATFORM', null, 'TRAFFIC_GATE');

        return [
            'status' => $readiness !== TrafficGateAdminReadiness::Ready
                ? 'CONFIGURATION REQUIRED'
                : ($enabled && ! $emergencyDisabled ? 'ENABLED' : 'DISABLED'),
            'requested_enabled' => $enabled,
            'emergency_disabled' => $emergencyDisabled,
            'readiness' => $readiness->value,
            'provider' => 'Cloudflare Turnstile',
            'validation_mode' => 'CLIENT-ONLY SOFT GATE',
            'widget' => 'Invisible',
            'origin' => trim((string) config('traffic_gate.origin')),
            'policy' => (string) $this->settings->get('traffic_gate.policy'),
            'initial_wait_ms' => (int) $this->settings->get('traffic_gate.initial_wait_ms'),
            'max_wait_ms' => (int) $this->settings->get('traffic_gate.max_wait_ms'),
            'retry_interval_ms' => (int) $this->settings->get('traffic_gate.retry_interval_ms'),
            'activity_recovery_enabled' => (bool) $this->settings->get('traffic_gate.activity_recovery_enabled'),
            'sitekey_configured' => trim((string) ($this->settings->get('traffic_gate.site_key') ?? '')) !== '',
        ];
    }

    /** @return array<string, int> */
    public function siteCounts(): array
    {
        $total = Site::withoutGlobalScopes()->count();
        $enabled = Site::withoutGlobalScopes()->whereHas('servingSettings', fn ($query) => $query->where('traffic_gate_state', TrafficGateSiteState::Enabled->value))->count();
        $disabled = Site::withoutGlobalScopes()->whereHas('servingSettings', fn ($query) => $query->where('traffic_gate_state', TrafficGateSiteState::Disabled->value))->count();

        return [
            'total' => $total,
            'enabled' => $enabled,
            'disabled' => $disabled,
            'inherit' => max(0, $total - $enabled - $disabled),
        ];
    }

    /** @return array<string, int> */
    public function impactCounts(): array
    {
        $sites = Site::withoutGlobalScopes()
            ->with('servingSettings')
            ->where('status', SiteStatus::Active->value)
            ->get();

        $globalEnable = $sites->filter(fn (Site $site): bool => ($site->servingSettings?->traffic_gate_state ?? TrafficGateSiteState::Inherit) === TrafficGateSiteState::Inherit)->count();
        $policy = $sites->filter(function (Site $site): bool {
            $sitePolicy = $site->servingSettings?->traffic_gate_policy ?? TrafficGateSitePolicy::Inherit;
            return $sitePolicy === TrafficGateSitePolicy::Inherit && $this->resolver->resolve($site)->enabled;
        })->count();

        return [
            'global_enable' => $globalEnable,
            'global_policy' => $policy,
            'active_sites' => $sites->count(),
        ];
    }

    /** @return array{state:string,pending:int,failed:int,deployed:int} */
    public function staticSummary(): array
    {
        $latest = StaticDeliveryItem::withoutGlobalScopes()
            ->latest('created_at')
            ->get()
            ->unique('site_id');

        $failed = $latest->where('status', StaticDeliveryStatus::Failed)->count();
        $pending = $latest->filter(fn (StaticDeliveryItem $item): bool => in_array($item->status, [
            StaticDeliveryStatus::Pending,
            StaticDeliveryStatus::Batching,
            StaticDeliveryStatus::Uploading,
            StaticDeliveryStatus::RetryScheduled,
        ], true))->count();
        $deployed = $latest->where('status', StaticDeliveryStatus::Deployed)->count();

        return [
            'state' => $failed > 0 ? 'FAILED' : ($pending > 0 ? 'PENDING NORMAL BATCH' : 'DEPLOYED'),
            'pending' => $pending,
            'failed' => $failed,
            'deployed' => $deployed,
        ];
    }

    /** @param Collection<int, Site> $sites @return array<string, string> */
    public function staticStatuses(Collection $sites): array
    {
        if ($sites->isEmpty()) {
            return [];
        }

        return StaticDeliveryItem::withoutGlobalScopes()
            ->whereIn('site_id', $sites->pluck('id'))
            ->latest('created_at')
            ->get()
            ->unique('site_id')
            ->mapWithKeys(function (StaticDeliveryItem $item): array {
                $label = match ($item->status) {
                    StaticDeliveryStatus::Failed => 'FAILED',
                    StaticDeliveryStatus::Deployed => 'DEPLOYED',
                    default => $item->priority->value === 'URGENT' ? 'PENDING URGENT' : 'PENDING NORMAL BATCH',
                };

                return [$item->site_id => $label];
            })
            ->all();
    }

    public function siteStaticStatus(Site $site): string
    {
        return $this->staticStatuses(collect([$site]))[$site->id] ?? 'NOT PUBLISHED';
    }
}
