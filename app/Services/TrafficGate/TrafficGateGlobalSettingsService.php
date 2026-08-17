<?php

namespace App\Services\TrafficGate;

use App\Enums\SiteStatus;
use App\Enums\StaticDeliveryPriority;
use App\Models\GlobalSetting;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Inventory\SiteConfigPublisher;
use App\Services\Settings\GlobalSettingsService;

final class TrafficGateGlobalSettingsService
{
    public function __construct(
        private readonly GlobalSettingsService $settings,
        private readonly SiteConfigPublisher $publisher,
        private readonly AuditRecorder $audit,
    ) {}

    public function set(User $actor, string $key, mixed $rawValue, ?string $reason = null): GlobalSetting
    {
        $before = $this->settings->get($key);
        $row = $this->settings->set($actor, $key, $rawValue, $reason);
        $after = $this->settings->get($key);

        $this->afterChange($actor, $key, $before, $after, $reason);

        return $row;
    }

    public function reset(User $actor, string $key, ?string $reason = null): void
    {
        $before = $this->settings->get($key);
        $this->settings->reset($actor, $key, $reason);
        $after = $this->settings->get($key);

        $this->afterChange($actor, $key, $before, $after, $reason);
    }

    private function afterChange(User $actor, string $key, mixed $before, mixed $after, ?string $reason): void
    {
        if ($before === $after) {
            return;
        }

        $event = match ($key) {
            'traffic_gate.enabled' => (bool) $after ? 'traffic_gate.enabled' : 'traffic_gate.disabled',
            'traffic_gate.policy' => 'traffic_gate.policy_changed',
            'traffic_gate.initial_wait_ms', 'traffic_gate.max_wait_ms', 'traffic_gate.retry_interval_ms' => 'traffic_gate.timings_changed',
            'traffic_gate.site_key' => 'traffic_gate.site_key_replaced',
            'traffic_gate.activity_recovery_enabled' => 'traffic_gate.activity_recovery_changed',
            default => null,
        };

        if ($event !== null) {
            $this->audit->record(
                $event,
                null,
                $actor,
                null,
                ['setting_key' => $key, 'value' => $before],
                ['setting_key' => $key, 'value' => $after],
                ['reason' => $reason ? mb_substr($reason, 0, 500) : null, 'client_only' => true],
            );
        }

        Site::withoutGlobalScopes()
            ->where('status', SiteStatus::Active->value)
            ->orderBy('id')
            ->each(fn (Site $site) => $this->publisher->publishActiveProduction(
                $site,
                $actor,
                StaticDeliveryPriority::Normal,
            ));
    }
}
