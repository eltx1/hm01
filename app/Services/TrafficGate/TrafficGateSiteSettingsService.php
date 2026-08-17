<?php

namespace App\Services\TrafficGate;

use App\Enums\StaticDeliveryPriority;
use App\Enums\TrafficGateSitePolicy;
use App\Enums\TrafficGateSiteState;
use App\Models\ConfigVersion;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Inventory\SiteConfigPublisher;
use Illuminate\Support\Facades\DB;

final class TrafficGateSiteSettingsService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly SiteConfigPublisher $publisher,
    ) {}

    public function update(
        Site $site,
        TrafficGateSiteState $state,
        TrafficGateSitePolicy $policy,
        User $actor,
        string $reason,
    ): ?ConfigVersion {
        $settings = $site->servingSettings()->firstOrFail();
        $before = [
            'traffic_gate_state' => $settings->traffic_gate_state->value,
            'traffic_gate_policy' => $settings->traffic_gate_policy->value,
        ];
        $after = [
            'traffic_gate_state' => $state->value,
            'traffic_gate_policy' => $policy->value,
        ];

        if ($before === $after) {
            return null;
        }

        DB::transaction(function () use ($settings, $state, $policy, $site, $actor, $reason, $before, $after): void {
            $settings->update([
                'traffic_gate_state' => $state,
                'traffic_gate_policy' => $policy,
                'configuration_version' => (int) $settings->configuration_version + 1,
            ]);

            $this->audit->record(
                'traffic_gate.site_override_changed',
                $site->organization_id,
                $actor,
                $site,
                $before,
                $after,
                ['reason' => mb_substr($reason, 0, 2000), 'client_only' => true],
            );
        });

        return $this->publisher->publishActiveProduction(
            $site->refresh(),
            $actor,
            StaticDeliveryPriority::Normal,
        );
    }
}
