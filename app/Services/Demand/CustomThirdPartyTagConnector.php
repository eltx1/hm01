<?php

namespace App\Services\Demand;

use App\Enums\DemandApprovalStatus;
use App\Models\DemandPlacement;
use App\Models\DemandWidget;
use RuntimeException;

final class CustomThirdPartyTagConnector extends AbstractDemandConnector
{
    protected function code(): string
    {
        return 'CUSTOM_THIRD_PARTY_TAG';
    }

    protected function widget(DemandPlacement $placement): ?DemandWidget
    {
        $placement->loadMissing(['widgets', 'placement.sizes', 'demandSite.site']);
        $approved = $placement->widgets
            ->filter(fn (DemandWidget $widget) => $widget->is_enabled
                && $widget->approval_status === DemandApprovalStatus::Approved);

        // Quick Monetize owns the generated mapping. A renamed Quick widget is
        // still the same renderer, and older releases may already have produced
        // more than one Quick-managed row. DemandWidget uses sortable ULIDs, so
        // the greatest id is the deterministic newest row independent of SQL
        // timestamp precision. Normal Advanced/legacy mappings retain their
        // historical oldest-approved selection semantics.
        if ((bool) data_get($placement->configuration, 'quick_monetize_managed', false)) {
            $quick = $approved
                ->filter(fn (DemandWidget $widget) => (bool) data_get($widget->configuration, 'quick_monetize_managed', false))
                ->sortByDesc('id')
                ->first();
            if ($quick) {
                return $quick;
            }
        }

        return $approved->sortBy('created_at')->first();
    }

    public function parseDirectTag(string $tag): array
    {
        $parsed = (new DirectTagRecipeParser())->parse($tag);
        $warnings = array_values(array_unique((array) ($parsed['securityWarnings'] ?? [])));
        $gpt = null;

        if (! (bool) ($parsed['containsSensitiveMaterial'] ?? false)) {
            try {
                $this->assertSafeCustomHtml($tag);
                $gpt = (new GoogleGptManualTagParser())->parse($tag);
            } catch (RuntimeException $exception) {
                $warnings[] = $exception->getMessage();
            }
        }

        foreach ((array) ($parsed['detectedScripts'] ?? []) as $script) {
            try {
                $this->assertAllowedScriptUrl((string) ($script['url'] ?? ''));
            } catch (RuntimeException $exception) {
                $warnings[] = $exception->getMessage();
            }
        }

        if (count((array) ($parsed['detectedContainers'] ?? [])) !== 1) {
            $warnings[] = 'A custom third-party tag must resolve to exactly one render container.';
        }

        $warnings = array_values(array_unique($warnings));
        $recipe = null;
        if ($warnings === []) {
            $recipe = $gpt
                ? ['executionMode' => 'STRUCTURED', 'provider' => 'GOOGLE_GPT', 'slot' => $gpt]
                : ['executionMode' => 'STRUCTURED', 'provider' => 'HORUS_ISOLATED'];
        }

        return [
            'safe' => ! (bool) ($parsed['containsSensitiveMaterial'] ?? false) && $warnings === [],
            'recipe' => $recipe,
            'detectedScripts' => $parsed['detectedScripts'] ?? [],
            'detectedContainers' => $parsed['detectedContainers'] ?? [],
            'detectedPublicIdentifiers' => $parsed['detectedPublicIdentifiers'] ?? [],
            'detectedAttributes' => data_get($parsed, 'detectedContainers.0.attributes', []),
            'unsupportedInlineCode' => [],
            'securityWarnings' => $warnings,
        ];
    }

    public function generateDirectTag(DemandPlacement $placement): array
    {
        $widget = $this->widget($placement);
        $configuration = $this->mergedConfiguration($placement, $widget);

        // Preserve the precedence ConfiguredDemandConnector historically gave
        // to an explicitly reviewed structured recipe. Existing production rows
        // can therefore move to this connector without changing their renderer.
        if (is_array($configuration['direct_recipe'] ?? null)) {
            return parent::generateDirectTag($placement);
        }

        if (! $widget?->direct_tag_template) {
            return parent::generateDirectTag($placement);
        }

        $html = trim((string) $widget->direct_tag_template);
        $this->assertSafeCustomHtml($html);
        $quickManaged = $this->isQuickManaged($placement, $widget);

        // Trusted GPT normalization is intentionally a Quick Monetize behavior.
        // Existing Advanced/legacy custom-tag mappings keep their historical
        // opaque iframe contract instead of being silently migrated to a new
        // renderer merely because their HTML happens to contain GPT syntax.
        if ($quickManaged) {
            $gpt = (new GoogleGptManualTagParser())->parse($html);
            if ($gpt !== null) {
                foreach ($this->externalScriptUrls($html) as $url) {
                    $this->assertAllowedScriptUrl($url);
                }

                return $this->googleGptRecipe($gpt, $configuration, $placement);
            }
        }

        $origins = $this->isolationOrigins($configuration);
        if ($origins === []) {
            throw new RuntimeException('Custom isolated tags require explicit provider CSP origins.');
        }
        foreach ($this->externalScriptUrls($html) as $url) {
            $this->assertAllowedScriptUrl($url);
        }

        if (! $quickManaged) {
            return $this->legacyIsolatedRecipe($html, $origins, $configuration, $placement);
        }

        return $this->isolatedRuntimeRecipe($html, $origins, $configuration, $placement);
    }

    public function generateGamCreative(DemandPlacement $placement): array
    {
        $widget = $this->widget($placement);
        $snippet = trim((string) ($widget?->gam_creative_template ?: $widget?->direct_tag_template));
        if ($snippet === '') {
            return parent::generateGamCreative($placement);
        }

        $this->assertSafeCustomHtml($snippet);
        foreach ($this->externalScriptUrls($snippet) as $url) {
            $this->assertAllowedScriptUrl($url);
        }

        $size = $placement->placement->sizes
            ->where('is_active', true)
            ->first(fn ($candidate) => $candidate->size_type === 'FIXED' && $candidate->width && $candidate->height);

        return [
            'name' => $this->code().' - '.$placement->placement->name,
            'creativeType' => 'THIRD_PARTY',
            'size' => $size
                ? ['width' => (int) $size->width, 'height' => (int) $size->height]
                : ['width' => 1, 'height' => 1],
            'snippet' => $snippet,
            'safeFrameCompatible' => true,
        ];
    }

    /**
     * Google GPT is normalized to data, never replayed as pasted JavaScript.
     * A Horus-owned runtime creates an independent GPT frame for the slot,
     * avoiding arbitrary inline execution and top-page GPT state conflicts.
     *
     * @param array{scriptUrl:string,adUnitPath:string,containerId:string,sizes:array<int,array{0:int,1:int}>} $gpt
     * @return array<string, mixed>
     */
    private function googleGptRecipe(array $gpt, array $configuration, DemandPlacement $placement): array
    {
        $runtimeUrl = $this->trustedRuntimeUrl('hm-gpt-direct.js');
        $containerId = $gpt['containerId'];
        $sizes = $gpt['sizes'];
        $placementSizes = $this->placementSizes($placement);
        if ($placementSizes === []) {
            throw new RuntimeException('The selected Horus placement has no active fixed display size for Google GPT.');
        }
        foreach ($sizes as $size) {
            if (! collect($placementSizes)->contains(fn (array $allowed): bool => $allowed === $size)) {
                throw new RuntimeException('The Google GPT slot size '.implode('x', $size).' is not enabled on the selected Horus placement.');
            }
        }

        $timeout = max(500, min(10000, (int) ($configuration['render_timeout_ms'] ?? config('demand.direct_render_timeout_ms', 2500))));

        return [
            'recipeVersion' => 1,
            'executionMode' => 'STRUCTURED',
            'format' => 'DISPLAY',
            'scripts' => [[
                'url' => $runtimeUrl,
                'async' => true,
                'defer' => false,
                'dedupeKey' => 'horus-google-gpt-direct-runtime-v1',
                'attributes' => [],
            ]],
            'container' => [
                'element' => 'div',
                'id' => $containerId,
                'class' => 'hm-direct-google-gpt',
                'attributes' => [
                    'data-hm-gpt-direct' => '1',
                    'data-hm-gpt-ad-unit-path' => $gpt['adUnitPath'],
                    'data-hm-gpt-sizes' => json_encode($sizes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ],
            ],
            'publicPlacementId' => $gpt['adUnitPath'],
            'initialization' => ['type' => 'NONE', 'parameters' => []],
            'render' => [
                'timeoutMs' => $timeout,
                'successSelector' => '#'.$containerId.'[data-hm-gpt-status="requested"]',
                'assumeLoadedIsSuccess' => false,
                'allowedFormats' => ['DISPLAY'],
                'allowedSizes' => $sizes,
            ],
            'isolation' => null,
            'scriptUrl' => $runtimeUrl,
            'containerId' => $containerId,
            'containerClass' => 'hm-direct-google-gpt',
            'attributes' => [
                'data-hm-gpt-direct' => '1',
                'data-hm-gpt-ad-unit-path' => $gpt['adUnitPath'],
                'data-hm-gpt-sizes' => json_encode($sizes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ],
            'renderTimeoutMs' => $timeout,
            'successSelector' => '#'.$containerId.'[data-hm-gpt-status="requested"]',
            'assumeLoadedIsSuccess' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function isolatedRuntimeRecipe(string $html, array $origins, array $configuration, DemandPlacement $placement): array
    {
        $frameSize = $this->quickPlacementSize($placement);
        $sizes = [$frameSize];
        $originList = implode(' ', $origins);
        $csp = $this->isolatedCsp($originList);
        $runtimeUrl = $this->trustedRuntimeUrl('hm-isolated-direct.js');
        $containerId = 'hm-isolated-'.$placement->id;
        $timeout = max(500, min(10000, (int) ($configuration['render_timeout_ms'] ?? config('demand.direct_render_timeout_ms', 2500))));
        $format = strtoupper((string) ($configuration['format'] ?? $placement->placement->type->value));
        $attributes = [
            'data-hm-isolated-direct' => '1',
            'data-hm-isolated-width' => (string) $frameSize[0],
            'data-hm-isolated-height' => (string) $frameSize[1],
        ];
        $attributes += $this->encodedPayloadAttributes('data-hm-isolated-html', $html);
        $attributes += $this->encodedPayloadAttributes('data-hm-isolated-csp', $csp);

        return [
            'recipeVersion' => 1,
            'executionMode' => 'STRUCTURED',
            'format' => $format,
            'scripts' => [[
                'url' => $runtimeUrl,
                'async' => true,
                'defer' => false,
                'dedupeKey' => 'horus-isolated-direct-runtime-v1',
                'attributes' => [],
            ]],
            'container' => [
                'element' => 'div',
                'id' => $containerId,
                'class' => 'hm-direct-demand-isolated',
                'attributes' => $attributes,
            ],
            'publicPlacementId' => (string) ($placement->remote_placement_id ?? $placement->placement_code ?? $placement->id),
            'initialization' => ['type' => 'NONE', 'parameters' => []],
            'render' => [
                'timeoutMs' => $timeout,
                'successSelector' => '#'.$containerId.'[data-hm-isolated-status="requested"]',
                'assumeLoadedIsSuccess' => false,
                'allowedFormats' => [$format],
                'allowedSizes' => $sizes,
            ],
            'isolation' => null,
            'scriptUrl' => $runtimeUrl,
            'containerId' => $containerId,
            'containerClass' => 'hm-direct-demand-isolated',
            'attributes' => $attributes,
            'renderTimeoutMs' => $timeout,
            'successSelector' => '#'.$containerId.'[data-hm-isolated-status="requested"]',
            'assumeLoadedIsSuccess' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function legacyIsolatedRecipe(string $html, array $origins, array $configuration, DemandPlacement $placement): array
    {
        $originList = implode(' ', $origins);
        $format = strtoupper((string) ($configuration['format'] ?? $placement->placement->type->value));
        $timeout = max(500, min(10000, (int) ($configuration['render_timeout_ms'] ?? config('demand.direct_render_timeout_ms', 2500))));
        $containerId = 'hm-isolated-'.$placement->id;

        return [
            'recipeVersion' => 1,
            'executionMode' => 'ISOLATED_IFRAME',
            'format' => $format,
            'scripts' => [],
            'container' => [
                'element' => 'div',
                'id' => $containerId,
                'class' => 'hm-direct-demand-isolated',
                'attributes' => [],
            ],
            'publicPlacementId' => (string) ($placement->remote_placement_id ?? $placement->placement_code ?? $placement->id),
            'initialization' => ['type' => 'NONE', 'parameters' => []],
            'render' => [
                'timeoutMs' => $timeout,
                'successSelector' => null,
                'assumeLoadedIsSuccess' => true,
                'allowedFormats' => [$format],
                // Keep legacy/Advanced mappings size-neutral. In particular,
                // fluid/native mappings must continue rendering exactly as they
                // did before the Quick Monetize connector was introduced.
                'allowedSizes' => [],
            ],
            'isolation' => [
                'html' => $html,
                'csp' => $this->legacyIsolatedCsp($originList),
                'sandbox' => ['allow-scripts'],
            ],
            'scriptUrl' => '',
            'containerId' => $containerId,
            'containerClass' => 'hm-direct-demand-isolated',
            'attributes' => [],
            'renderTimeoutMs' => $timeout,
            'successSelector' => null,
            'assumeLoadedIsSuccess' => true,
        ];
    }

    private function isolatedCsp(string $originList): string
    {
        return "default-src 'none'; script-src 'unsafe-inline' {$originList}; connect-src {$originList}; img-src {$originList} data:; style-src 'unsafe-inline'; frame-src {$originList}; font-src {$originList} data:; media-src {$originList}; base-uri 'none'; form-action 'none'; object-src 'none';";
    }

    private function legacyIsolatedCsp(string $originList): string
    {
        // This is intentionally the historical compatibility policy. Existing
        // Advanced/native creatives may load images from HTTPS hosts other than
        // their script origin. Tightening that policy during an unrelated Quick
        // Monetize rollout would silently break already-serving inventory.
        return "default-src 'none'; script-src 'unsafe-inline' {$originList}; connect-src {$originList}; img-src https: data:; style-src 'unsafe-inline'; frame-src {$originList};";
    }

    /** @return array<string, string> */
    private function encodedPayloadAttributes(string $baseAttribute, string $payload): array
    {
        $encoded = base64_encode($payload);
        // DemandConfigurationBuilder intentionally caps every public data-*
        // attribute at 2,000 characters. Keep small payloads on the historical
        // single attribute and chunk larger payloads below that boundary so an
        // approved 60 KB tag can never be silently truncated in static config.
        if (strlen($encoded) <= 1800) {
            return [$baseAttribute => $encoded];
        }

        $parts = str_split($encoded, 1800);
        if (count($parts) > 64) {
            throw new RuntimeException('The encoded isolated tag exceeds the trusted runtime payload limit.');
        }

        $attributes = [$baseAttribute.'-parts' => (string) count($parts)];
        foreach ($parts as $index => $part) {
            $attributes[$baseAttribute.'-'.$index] = $part;
        }

        return $attributes;
    }

    private function isQuickManaged(DemandPlacement $placement, ?DemandWidget $widget): bool
    {
        return (bool) data_get($placement->configuration, 'quick_monetize_managed', false)
            || (bool) data_get($widget?->configuration, 'quick_monetize_managed', false);
    }

    /** @return array{0:int,1:int} */
    private function quickPlacementSize(DemandPlacement $placement): array
    {
        $placement->loadMissing('placement.sizes');
        $active = $placement->placement->sizes->where('is_active', true)->values();
        if ($active->count() !== 1) {
            throw new RuntimeException('Quick Monetize generic tags require exactly one active fixed size on the selected Horus placement. Use a dedicated single-size placement or Advanced setup for responsive, fluid, or multi-size inventory.');
        }

        $size = $active->first();
        if (! $size || $size->size_type !== 'FIXED' || ! $size->width || ! $size->height) {
            throw new RuntimeException('Quick Monetize generic tags require exactly one active fixed size on the selected Horus placement. Use a dedicated single-size placement or Advanced setup for responsive, fluid, or multi-size inventory.');
        }

        return [(int) $size->width, (int) $size->height];
    }

    private function trustedRuntimeUrl(string $asset): string
    {
        $base = rtrim((string) config('horus.cdn_url'), '/');
        if ($base === '') {
            $base = 'https://cdn.horusmedia.net';
        }
        $url = $base.'/assets/'.$asset;
        if (! filter_var($url, FILTER_VALIDATE_URL)
            || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
            || (string) parse_url($url, PHP_URL_HOST) === '') {
            throw new RuntimeException('Horus Direct Demand runtime requires a trusted HTTPS CDN URL.');
        }

        return $url;
    }

    protected function assertAllowedScriptUrl(string $url): void
    {
        $origin = $this->canonicalHttpsOrigin($url);
        if ($origin === null) {
            throw new RuntimeException('Demand script URLs must be valid HTTPS URLs without embedded credentials.');
        }

        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if (! $this->isPublicScriptHost($host)) {
            throw new RuntimeException("Demand script host [{$host}] is private, reserved, or otherwise not valid for publisher delivery.");
        }

        $allowed = collect($this->selectedAccount->network->script_origins ?? [])
            ->merge((array) config('demand.allowed_script_origins.'.$this->code(), []))
            ->merge((array) data_get($this->selectedAccount->configuration, 'allowed_script_origins', []))
            ->map(fn ($value) => $this->canonicalHttpsOrigin((string) $value))
            ->filter()
            ->unique();

        if ($allowed->isEmpty() || ! $allowed->contains($origin)) {
            throw new RuntimeException("Demand script origin [{$origin}] is not allowlisted for ".$this->code().'.');
        }
    }

    private function canonicalHttpsOrigin(string $url): ?string
    {
        $url = trim($url);
        if (! filter_var($url, FILTER_VALIDATE_URL)
            || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null) {
            return null;
        }

        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if ($host === '') {
            return null;
        }
        $originHost = str_contains($host, ':') ? '['.$host.']' : $host;
        $port = parse_url($url, PHP_URL_PORT);

        return 'https://'.$originHost.($port && (int) $port !== 443 ? ':'.(int) $port : '');
    }

    private function assertSafeCustomHtml(string $html): void
    {
        if ($html === '' || strlen($html) > 60_000) {
            throw new RuntimeException('The configured third-party creative is empty or exceeds the safe size limit.');
        }

        $unsafeCaseInsensitive = [
            '/document\s*\.\s*cookie/i',
            '/(?:local|session)Storage/i',
            '/javascript\s*:/i',
            '/\beval\s*\(/i',
            '/(?:window\s*\.\s*)?top\s*\.\s*(?:location|document)/i',
            '/<\s*(?:object|embed|applet|base|meta)\b/i',
            '/(?:env|file):[A-Za-z0-9_\/.:-]+/i',
            '/constructor\s*\.\s*constructor/i',
        ];

        foreach ($unsafeCaseInsensitive as $pattern) {
            if (preg_match($pattern, $html)) {
                throw new RuntimeException('The configured third-party creative contains unsafe or private content.');
            }
        }

        // JavaScript is case-sensitive: Function(...) is the dynamic-code
        // constructor, while function (...) is the ordinary callback syntax
        // used by Google Publisher Tag and many legitimate provider tags.
        if (preg_match('/\bFunction\s*\(/', $html)
            || preg_match('/["\']Function["\']\s*\]/', $html)) {
            throw new RuntimeException('The configured third-party creative contains a dynamic Function constructor.');
        }

        if (preg_match('/(?:src|href)\s*=\s*(["\'])http:\/\//i', $html)) {
            throw new RuntimeException('Third-party creative assets must use HTTPS.');
        }
    }

    /** @return array<int, string> */
    private function externalScriptUrls(string $html): array
    {
        if (! preg_match_all('/<script\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1/is', $html, $matches)) {
            return [];
        }

        return collect($matches[2])
            ->map(function ($url): string {
                $url = html_entity_decode(trim((string) $url), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return str_starts_with($url, '//') ? 'https:'.$url : $url;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, array{0:int,1:int}> */
    private function placementSizes(DemandPlacement $placement): array
    {
        $placement->loadMissing('placement.sizes');

        return $placement->placement->sizes
            ->where('is_active', true)
            ->filter(fn ($size) => $size->size_type === 'FIXED' && $size->width && $size->height)
            ->map(fn ($size): array => [(int) $size->width, (int) $size->height])
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function isolationOrigins(array $configuration): array
    {
        return collect((array) ($configuration['isolation_allowed_origins'] ?? []))
            ->map(fn ($origin) => strtolower(rtrim((string) $origin, '/')))
            ->filter(function (string $origin): bool {
                if (! filter_var($origin, FILTER_VALIDATE_URL)
                    || strtolower((string) parse_url($origin, PHP_URL_SCHEME)) !== 'https') {
                    return false;
                }
                $host = strtolower(trim((string) parse_url($origin, PHP_URL_HOST), '[]'));

                return $host !== ''
                    && $host !== 'app.horusmedia.net'
                    && ! str_ends_with($host, '.app.horusmedia.net')
                    && $this->isPublicScriptHost($host);
            })
            ->unique()
            ->take(20)
            ->values()
            ->all();
    }

    private function isPublicScriptHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === '' || $host === 'localhost' || $host === 'localhost.localdomain') {
            return false;
        }
        foreach (['.localhost', '.local', '.internal', '.home.arpa', '.test', '.invalid', '.example'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
        }

        return str_contains($host, '.')
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
