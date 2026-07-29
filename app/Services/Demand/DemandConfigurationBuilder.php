<?php

namespace App\Services\Demand;

use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Enums\PlacementStatus;
use App\Models\DemandPlacement;
use App\Models\DemandSite;
use App\Models\Site;
use Throwable;

final class DemandConfigurationBuilder
{
    public function __construct(private readonly DemandConnectorManager $connectors)
    {
    }

    public function build(Site $site): array
    {
        if (! $site->native_demand_enabled) {
            return $this->disabled();
        }

        $mappings = DemandSite::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('is_enabled', true)
            ->where('approval_status', DemandApprovalStatus::Approved->value)
            ->with([
                'account' => fn ($query) => $query->withoutGlobalScopes()->with('network'),
                'placements.placement',
                'placements.widgets',
            ])
            ->get()
            ->filter(fn (DemandSite $mapping) => $mapping->account
                && $mapping->account->is_enabled
                && $mapping->account->approval_status === DemandApprovalStatus::Approved
                && $mapping->account->network?->is_enabled);

        $placements = [];

        foreach ($mappings as $mapping) {
            foreach ($mapping->placements as $demandPlacement) {
                if (! $this->eligible($demandPlacement)) {
                    continue;
                }

                $mode = $demandPlacement->integration_mode
                    ?? $mapping->integration_mode
                    ?? $mapping->account->integration_mode;
                $priority = (int) (
                    $demandPlacement->fallback_priority
                    ?? $mapping->fallback_priority
                    ?? $mapping->account->fallback_priority
                );

                $candidate = [
                    'network' => $mapping->account->network->code->value,
                    'mode' => $mode->value,
                    'priority' => $priority,
                    'gamManaged' => in_array($mode, [
                        DemandIntegrationMode::GamThirdPartyCreative,
                        DemandIntegrationMode::GamLineItem,
                    ], true),
                ];

                if (! $candidate['gamManaged']) {
                    try {
                        $candidate['tag'] = $this->sanitizePublicTag(
                            $this->connectors
                                ->for($mapping->account)
                                ->generateDirectTag($demandPlacement)
                        );
                    } catch (Throwable) {
                        continue;
                    }
                }

                $code = $demandPlacement->placement->code;
                $placements[$code] ??= [
                    'enabled' => true,
                    'candidates' => [],
                    'house' => null,
                ];
                $placements[$code]['candidates'][] = $candidate;

                $houseHtml = data_get($demandPlacement->configuration, 'house_html');
                if (is_string($houseHtml) && trim($houseHtml) !== '') {
                    $placements[$code]['house'] = ['html' => $this->sanitizeHouseHtml($houseHtml)];
                }
            }
        }

        foreach ($placements as &$placement) {
            usort($placement['candidates'], fn (array $left, array $right) => $left['priority'] <=> $right['priority']);
        }
        unset($placement);

        return [
            'enabled' => $placements !== [],
            'fallbackOrder' => array_values(config('demand.fallback_order', ['GAM', 'MGID', 'TABOOLA', 'SPEAKOL', 'OUTBRAIN', 'HOUSE'])),
            'placements' => $placements,
        ];
    }

    private function eligible(DemandPlacement $mapping): bool
    {
        return $mapping->is_enabled
            && $mapping->approval_status === DemandApprovalStatus::Approved
            && $mapping->placement
            && $mapping->placement->status === PlacementStatus::Active;
    }

    private function sanitizePublicTag(array $tag): array
    {
        $attributes = collect((array) ($tag['attributes'] ?? []))
            ->filter(fn ($value, $key) => is_scalar($value)
                && preg_match('/^data-[a-z0-9_.:-]+$/i', (string) $key)
                && ! preg_match('/secret|token|password|credential|private|key/i', (string) $key))
            ->map(fn ($value) => mb_substr((string) $value, 0, 2000))
            ->reject(fn ($value) => preg_match('/javascript\s*:/i', $value))
            ->all();

        $containerId = preg_replace('/[^A-Za-z0-9_:-]/', '-', (string) ($tag['containerId'] ?? '')) ?: '';
        $containerClass = preg_replace('/[^A-Za-z0-9_\- ]/', '-', (string) ($tag['containerClass'] ?? 'hm-native-container')) ?: 'hm-native-container';
        $successSelector = isset($tag['successSelector']) && is_scalar($tag['successSelector'])
            ? mb_substr((string) $tag['successSelector'], 0, 1000)
            : null;

        return [
            'scriptUrl' => (string) ($tag['scriptUrl'] ?? ''),
            'containerId' => $containerId,
            'containerClass' => $containerClass,
            'attributes' => $attributes,
            'renderTimeoutMs' => max(500, min(10000, (int) ($tag['renderTimeoutMs'] ?? config('demand.direct_render_timeout_ms', 2500)))),
            'successSelector' => $successSelector,
            'assumeLoadedIsSuccess' => (bool) ($tag['assumeLoadedIsSuccess'] ?? false),
        ];
    }

    private function sanitizeHouseHtml(string $html): string
    {
        $html = preg_replace('#<(script|iframe|object|embed|applet|style)\b[^>]*>.*?</\1>#is', '', $html) ?? '';
        $html = preg_replace('/\son[a-z]+\s*=\s*(["\']).*?\1/is', '', $html) ?? '';
        $html = preg_replace('/javascript\s*:/i', '', $html) ?? '';

        return strip_tags($html, '<div><span><p><a><img><strong><em><small><h1><h2><h3><ul><ol><li>');
    }

    private function disabled(): array
    {
        return ['enabled' => false, 'fallbackOrder' => [], 'placements' => []];
    }
}
