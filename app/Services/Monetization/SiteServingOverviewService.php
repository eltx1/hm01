<?php

namespace App\Services\Monetization;

use App\Enums\ConfigEnvironment;
use App\Enums\PrebidDeliveryMode;
use App\Models\ConfigVersion;
use App\Models\Site;
use App\Services\Serving\SiteEngineStateResolver;

final class SiteServingOverviewService
{
    public function __construct(
        private readonly SiteEngineStateResolver $engines,
        private readonly SiteMonetizationReadinessService $readiness,
        private readonly ReportingHealthService $reporting,
    ) {}

    /** @return array<string,mixed> */
    public function forSite(Site $site): array
    {
        $state = $this->engines->resolve($site);
        $readiness = $this->readiness->admin($site);
        $modules = collect($readiness['modules'])->keyBy('key');
        $production = ConfigVersion::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('environment', ConfigEnvironment::Production->value)
            ->whereNull('rolled_back_at')
            ->latest('version')
            ->first();
        $payload = (array) ($production?->payload ?? []);
        $prebidCodes = collect((array) data_get($payload, 'prebid.adUnits', []))->pluck('code')->filter()->values()->all();

        $directProviders = collect((array) data_get($payload, 'directDemand.placements', []))
            ->flatMap(fn (array $placement) => collect((array) ($placement['candidates'] ?? []))->pluck('network'))
            ->filter()->unique()->values()->all();

        $matrix = collect((array) ($payload['placements'] ?? []))->map(function (array $placement) use ($state, $prebidCodes, $payload): array {
            $code = (string) ($placement['code'] ?? '');
            $direct = collect((array) data_get($payload, 'directDemand.placements.'.$code.'.candidates', []))
                ->filter(fn (array $candidate) => ! (bool) ($candidate['gamManaged'] ?? false))
                ->pluck('network')->filter()->unique()->values()->all();
            $prebid = in_array($code, $prebidCodes, true)
                ? $state->prebidDeliveryMode->value
                : 'OFF';

            return [
                'placement' => $code,
                'name' => $placement['name'] ?? $code,
                'gam' => (bool) ($placement['gamEnabled'] ?? false) ? 'ON' : 'OFF',
                'prebid' => $prebid,
                'direct_js' => $direct === [] ? 'OFF' : implode(', ', $direct),
                'renderer' => $placement['renderer'] ?? 'NONE',
                'status' => (bool) ($placement['rendererConflict'] ?? false)
                    ? 'CONFLICT'
                    : ((bool) ($placement['enabled'] ?? false) ? 'ACTIVE' : 'DISABLED'),
            ];
        })->values()->all();

        $prebidModule = (array) ($modules->get('prebid') ?? []);
        $directModule = (array) ($modules->get('native') ?? []);
        $gamModule = (array) ($modules->get('display') ?? []);
        $reporting = $this->reporting->forSite($site);

        return [
            'master' => [
                'status' => $state->masterServingEnabled ? 'ON' : 'OFF',
                'health' => $readiness['overall']['status'],
            ],
            'gam' => [
                'status' => ! $state->gamRequired ? 'NOT_CONFIGURED' : ($state->gamEnabled ? 'ON' : 'OFF'),
                'mode' => $site->serving_mode->value,
                'connection' => $state->gamConnection?->name,
                'connection_id' => $state->gamConnection?->id,
                'health' => $gamModule['status'] ?? 'NOT_CONFIGURED',
                'reason' => $state->gamReason,
            ],
            'prebid' => [
                'status' => $state->prebidEnabled ? 'ON' : 'OFF',
                'configured_mode' => $state->prebidConfiguredMode->value,
                'resolved_mode' => $state->prebidDeliveryMode->value,
                'build' => data_get($prebidModule, 'diagnostics.build'),
                'health' => $prebidModule['status'] ?? 'NOT_CONFIGURED',
                'reason' => $state->prebidReason,
            ],
            'direct_js' => [
                'status' => $state->directJsEnabled ? 'ON' : 'OFF',
                'networks' => data_get($directModule, 'diagnostics.provider_names', $directProviders),
                'placements' => (int) data_get($directModule, 'diagnostics.eligible_direct_placements', 0),
                'health' => $directModule['status'] ?? 'NOT_CONFIGURED',
                'reason' => $state->directJsReason,
            ],
            'placement_matrix' => $matrix,
            'reporting' => $reporting,
            'production_config' => [
                'version' => $production?->version,
                'status' => $production?->status?->value,
                'published_at' => $production?->published_at?->toIso8601String(),
            ],
        ];
    }
}
