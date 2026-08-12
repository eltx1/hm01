<?php

namespace App\Services\Demand;

use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Enums\PlacementStatus;
use App\Models\DemandPlacement;
use App\Models\DemandSite;
use App\Models\Site;
use App\Services\Operations\PlatformControlService;
use Throwable;

final class DemandConfigurationBuilder
{
    public function __construct(
        private readonly DemandConnectorManager $connectors,
        private readonly PlatformControlService $controls,
    ) {
    }

    public function build(Site $site): array
    {
        // native_demand_enabled is the rollout-compatible database master flag.
        // The public product/runtime concept is now Direct Demand.
        if (! $site->native_demand_enabled) {
            return $this->disabled();
        }

        $mappings = DemandSite::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('is_enabled', true)
            ->where('approval_status', DemandApprovalStatus::Approved->value)
            ->with([
                'account' => fn ($query) => $query->withoutGlobalScopes()->with('network'),
                'placements.placement.sizes',
                'placements.widgets',
            ])
            ->get()
            ->filter(fn (DemandSite $mapping) => $mapping->account
                && $mapping->account->is_enabled
                && $mapping->account->approval_status === DemandApprovalStatus::Approved
                && $mapping->account->network?->is_enabled
                && ! $this->networkDisabled($mapping, 'AD_SERVING')
                && ! $this->networkDisabled($mapping, 'NATIVE_DEMAND'));

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
                $gamManaged = in_array($mode, [
                    DemandIntegrationMode::GamThirdPartyCreative,
                    DemandIntegrationMode::GamLineItem,
                ], true);

                if (! $gamManaged && ($this->networkDisabled($mapping, 'DIRECT_JS') || ! $mapping->account->network->supports_direct_js)) {
                    continue;
                }

                $candidate = [
                    'network' => $mapping->account->network->code->value,
                    'mode' => $mode->value,
                    'priority' => $priority,
                    'gamManaged' => $gamManaged,
                ];

                if (! $gamManaged) {
                    try {
                        $candidate['tag'] = $this->sanitizePublicTag(
                            $this->connectors
                                ->for($mapping->account)
                                ->generateDirectTag($demandPlacement)
                        );
                    } catch (Throwable) {
                        // A malformed or unsafe provider recipe is not a page-level failure.
                        // It is simply absent from the public delivery configuration.
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
            'engine' => 'DIRECT_DEMAND',
            'recipeVersion' => 1,
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

    private function networkDisabled(DemandSite $mapping, string $control): bool
    {
        $networkId = $mapping->account?->network?->id;

        return $networkId ? $this->controls->disabled('DEMAND_NETWORK', $networkId, $control) : false;
    }

    /** @return array<string, mixed> */
    private function sanitizePublicTag(array $tag): array
    {
        $executionMode = strtoupper((string) ($tag['executionMode'] ?? 'STRUCTURED'));
        if (! in_array($executionMode, ['STRUCTURED', 'ISOLATED_IFRAME'], true)) {
            throw new \RuntimeException('Unsupported Direct Demand execution mode.');
        }

        $scripts = collect((array) ($tag['scripts'] ?? []))
            ->take(8)
            ->map(function ($script): array {
                $script = (array) $script;
                $url = trim((string) ($script['url'] ?? ''));
                if (! filter_var($url, FILTER_VALIDATE_URL) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
                    throw new \RuntimeException('Direct Demand scripts must use HTTPS.');
                }
                if ($this->containsSensitive($script) || preg_match('/javascript\s*:/i', $url)) {
                    throw new \RuntimeException('Unsafe Direct Demand script recipe.');
                }

                return [
                    'url' => $url,
                    'async' => (bool) ($script['async'] ?? true),
                    'defer' => (bool) ($script['defer'] ?? false),
                    'dedupeKey' => preg_replace('/[^A-Za-z0-9_.:-]/', '-', (string) ($script['dedupeKey'] ?? hash('sha256', $url))),
                    'attributes' => $this->publicAttributes((array) ($script['attributes'] ?? [])),
                ];
            })
            ->values()
            ->all();

        $container = (array) ($tag['container'] ?? []);
        $element = strtolower((string) ($container['element'] ?? 'div'));
        if (! in_array($element, ['div', 'span', 'aside', 'section'], true)) {
            throw new \RuntimeException('Unsupported Direct Demand container element.');
        }
        $containerId = preg_replace('/[^A-Za-z0-9_:-]/', '-', (string) ($container['id'] ?? $tag['containerId'] ?? '')) ?: '';
        $containerClass = preg_replace('/[^A-Za-z0-9_\- ]/', '-', (string) ($container['class'] ?? $tag['containerClass'] ?? 'hm-direct-demand-container')) ?: 'hm-direct-demand-container';
        $containerAttributes = $this->publicAttributes((array) ($container['attributes'] ?? $tag['attributes'] ?? []));

        $initialization = (array) ($tag['initialization'] ?? ['type' => 'NONE', 'parameters' => []]);
        $initializationType = strtoupper((string) ($initialization['type'] ?? 'NONE'));
        if (! in_array($initializationType, ['NONE', 'MGID_QUEUE_LOAD', 'TABOOLA_QUEUE', 'OUTBRAIN_RESEARCH'], true)) {
            throw new \RuntimeException('Unknown Direct Demand initialization action.');
        }
        $parameters = $this->publicParameters((array) ($initialization['parameters'] ?? []));

        $render = (array) ($tag['render'] ?? []);
        $successSelector = isset($render['successSelector']) || isset($tag['successSelector'])
            ? mb_substr((string) ($render['successSelector'] ?? $tag['successSelector']), 0, 1000)
            : null;
        $allowedFormats = collect((array) ($render['allowedFormats'] ?? $tag['allowedFormats'] ?? []))
            ->map(fn ($value) => strtoupper((string) $value))
            ->filter(fn ($value) => in_array($value, ['DISPLAY', 'NATIVE', 'VIDEO', 'OUTSTREAM'], true))
            ->unique()->values()->all();
        $allowedSizes = collect((array) ($render['allowedSizes'] ?? $tag['allowedSizes'] ?? []))
            ->filter(fn ($size) => is_array($size) && count($size) === 2 && (int) $size[0] > 0 && (int) $size[1] > 0)
            ->map(fn ($size) => [(int) $size[0], (int) $size[1]])
            ->values()->all();

        $isolation = null;
        if ($executionMode === 'ISOLATED_IFRAME') {
            $raw = (array) ($tag['isolation'] ?? []);
            $html = (string) ($raw['html'] ?? '');
            $csp = (string) ($raw['csp'] ?? '');
            if ($html === '' || strlen($html) > 60_000 || $this->containsSensitive($html) || preg_match('/javascript\s*:/i', $html)) {
                throw new \RuntimeException('Unsafe isolated third-party tag payload.');
            }
            if ($csp === '' || stripos($csp, 'script-src') === false) {
                throw new \RuntimeException('Isolated third-party tags require an explicit CSP.');
            }
            $sandbox = collect((array) ($raw['sandbox'] ?? []))
                ->filter(fn ($token) => in_array($token, ['allow-scripts'], true))
                ->unique()->values()->all();
            if ($sandbox !== ['allow-scripts']) {
                throw new \RuntimeException('Custom isolated tags use an opaque-origin script-only sandbox.');
            }
            $isolation = ['html' => $html, 'csp' => $csp, 'sandbox' => $sandbox];
        } elseif ($scripts === []) {
            throw new \RuntimeException('Structured Direct Demand requires at least one approved script.');
        }

        $timeout = max(500, min(10000, (int) ($render['timeoutMs'] ?? $tag['renderTimeoutMs'] ?? config('demand.direct_render_timeout_ms', 2500))));
        $publicPlacementId = mb_substr((string) ($tag['publicPlacementId'] ?? data_get($containerAttributes, 'data-widget-id') ?? $containerId), 0, 255);
        if ($this->containsSensitive([$publicPlacementId, $parameters, $containerAttributes])) {
            throw new \RuntimeException('Direct Demand public recipe contains sensitive material.');
        }

        $firstScript = $scripts[0]['url'] ?? '';

        return [
            'recipeVersion' => 1,
            'executionMode' => $executionMode,
            'format' => strtoupper((string) ($tag['format'] ?? ($allowedFormats[0] ?? 'DISPLAY'))),
            'scripts' => $scripts,
            'container' => [
                'element' => $element,
                'id' => $containerId,
                'class' => $containerClass,
                'attributes' => $containerAttributes,
            ],
            'publicPlacementId' => $publicPlacementId,
            'initialization' => ['type' => $initializationType, 'parameters' => $parameters],
            'render' => [
                'timeoutMs' => $timeout,
                'successSelector' => $successSelector,
                'assumeLoadedIsSuccess' => (bool) ($render['assumeLoadedIsSuccess'] ?? $tag['assumeLoadedIsSuccess'] ?? false),
                'allowedFormats' => $allowedFormats,
                'allowedSizes' => $allowedSizes,
            ],
            'isolation' => $isolation,

            // Schema-v3/legacy Loader compatibility during rollout.
            'scriptUrl' => $firstScript,
            'containerId' => $containerId,
            'containerClass' => $containerClass,
            'attributes' => $containerAttributes,
            'renderTimeoutMs' => $timeout,
            'successSelector' => $successSelector,
            'assumeLoadedIsSuccess' => (bool) ($render['assumeLoadedIsSuccess'] ?? $tag['assumeLoadedIsSuccess'] ?? false),
        ];
    }

    /** @return array<string, string> */
    private function publicAttributes(array $attributes): array
    {
        return collect($attributes)
            ->filter(fn ($value, $key) => is_scalar($value)
                && preg_match('/^(?:data|aria)-[a-z0-9_.:-]+$/i', (string) $key)
                && ! preg_match('/secret|token|password|credential|private|(?:^|[-_])key/i', (string) $key))
            ->map(fn ($value) => mb_substr((string) $value, 0, 2000))
            ->reject(fn ($value) => preg_match('/javascript\s*:/i', $value) || preg_match('/^(?:env|file):/i', $value))
            ->all();
    }

    /** @return array<string, string|int|float|bool> */
    private function publicParameters(array $parameters): array
    {
        $safe = [];
        foreach ($parameters as $key => $value) {
            $key = (string) $key;
            if (! is_scalar($value)
                || ! preg_match('/^[A-Za-z][A-Za-z0-9_.:-]*$/', $key)
                || preg_match('/secret|token|password|credential|authorization|api[_-]?key|private/i', $key)) {
                continue;
            }
            $string = is_string($value) ? trim($value) : $value;
            if (is_string($string) && (preg_match('/javascript\s*:/i', $string) || preg_match('/^(?:env|file):/i', $string))) {
                continue;
            }
            $safe[$key] = is_string($string) ? mb_substr($string, 0, 2000) : $string;
        }

        return $safe;
    }

    private function containsSensitive(mixed $value): bool
    {
        $encoded = is_string($value) ? $value : (json_encode($value, JSON_UNESCAPED_SLASHES) ?: '');

        return preg_match('/(?:env|file):[A-Za-z0-9_\/.:-]+|secret|password|credential|authorization|api[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret|private[_-]?key/i', $encoded) === 1;
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
        return [
            'enabled' => false,
            'engine' => 'DIRECT_DEMAND',
            'recipeVersion' => 1,
            'fallbackOrder' => [],
            'placements' => [],
        ];
    }
}
