<?php

namespace App\Services\TrafficGate;

use App\Enums\TrafficGatePolicy;
use App\Enums\TrafficGateReadiness;
use App\Enums\TrafficGateSitePolicy;
use App\Enums\TrafficGateSiteState;
use App\Models\Site;
use App\Services\Operations\PlatformControlService;
use App\Services\Settings\GlobalSettingsService;

final class TrafficGateConfigurationResolver
{
    public function __construct(
        private readonly GlobalSettingsService $settings,
        private readonly PlatformControlService $controls,
    ) {}

    public function resolve(Site $site): TrafficGateConfiguration
    {
        $site->loadMissing('servingSettings');
        $siteState = $site->servingSettings?->traffic_gate_state ?? TrafficGateSiteState::Inherit;
        $sitePolicy = $site->servingSettings?->traffic_gate_policy ?? TrafficGateSitePolicy::Inherit;
        $requestedEnabled = match ($siteState) {
            TrafficGateSiteState::Enabled => true,
            TrafficGateSiteState::Disabled => false,
            default => (bool) $this->settings->get('traffic_gate.enabled'),
        };
        $emergencyDisabled = $this->controls->disabled('PLATFORM', null, 'TRAFFIC_GATE');
        $policy = $sitePolicy === TrafficGateSitePolicy::Inherit
            ? TrafficGatePolicy::from((string) $this->settings->get('traffic_gate.policy'))
            : TrafficGatePolicy::from($sitePolicy->value);
        $origin = trim((string) config('traffic_gate.origin'));
        $siteKey = trim((string) ($this->settings->get('traffic_gate.site_key') ?? '')) ?: null;
        $initial = (int) $this->settings->get('traffic_gate.initial_wait_ms');
        $maximum = (int) $this->settings->get('traffic_gate.max_wait_ms');
        $retry = (int) $this->settings->get('traffic_gate.retry_interval_ms');
        $validTimings = $this->validTiming('initial_wait_ms', $initial)
            && $this->validTiming('max_wait_ms', $maximum)
            && $this->validTiming('retry_interval_ms', $retry)
            && $maximum >= $initial;

        $readiness = TrafficGateReadiness::Disabled;
        if ($requestedEnabled && ! $emergencyDisabled) {
            $readiness = $siteKey === null
                ? TrafficGateReadiness::ConfigurationRequired
                : ($this->validOrigin($origin) && $validTimings
                    ? TrafficGateReadiness::Ready
                    : TrafficGateReadiness::InvalidConfiguration);
        }

        return new TrafficGateConfiguration(
            enabled: $requestedEnabled && ! $emergencyDisabled && $readiness === TrafficGateReadiness::Ready,
            provider: (string) config('traffic_gate.provider', 'CLOUDFLARE_TURNSTILE_CLIENT_ONLY'),
            gateOrigin: $origin,
            siteKey: $siteKey,
            policy: $policy,
            initialWaitMs: $initial,
            maxWaitMs: $maximum,
            retryIntervalMs: $retry,
            activityRecoveryEnabled: (bool) $this->settings->get('traffic_gate.activity_recovery_enabled'),
            readiness: $readiness,
        );
    }

    public function validOrigin(string $origin): bool
    {
        $parts = parse_url($origin);
        if (! is_array($parts)) {
            return false;
        }

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'verify.horusmedia.net'
            && ! isset($parts['port'], $parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            && in_array($parts['path'] ?? '', ['', '/'], true);
    }

    private function validTiming(string $key, int $value): bool
    {
        [$minimum, $maximum] = (array) config("traffic_gate.bounds.{$key}", [1, 1]);

        return $value >= (int) $minimum && $value <= (int) $maximum;
    }
}
