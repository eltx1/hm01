<?php

namespace App\Services\Demand;

use App\Enums\DemandApprovalStatus;
use App\Models\DemandPlacement;
use App\Models\DemandWidget;
use App\Services\Security\PublicProviderOriginValidator;
use RuntimeException;

final class CustomThirdPartyTagConnector extends AbstractDemandConnector
{
    protected function code(): string { return 'CUSTOM_THIRD_PARTY_TAG'; }

    protected function widget(DemandPlacement $placement): ?DemandWidget
    {
        $placement->loadMissing(['widgets', 'placement.sizes', 'demandSite.site']);
        $approved = $placement->widgets->filter(fn (DemandWidget $widget) => $widget->is_enabled && $widget->approval_status === DemandApprovalStatus::Approved);
        if ((bool) data_get($placement->configuration, 'quick_monetize_managed', false)) {
            $quick = $approved->filter(fn (DemandWidget $widget) => (bool) data_get($widget->configuration, 'quick_monetize_managed', false))->sortByDesc('id')->first();
            if ($quick) return $quick;
        }
        return $approved->sortBy('created_at')->first();
    }

    public function parseDirectTag(string $tag): array
    {
        try {
            $vast = app(VastTagUrlParser::class)->parse($tag);
        } catch (RuntimeException $exception) {
            return ['safe' => false, 'recipe' => null, 'detectedScripts' => [], 'detectedContainers' => [], 'detectedPublicIdentifiers' => [], 'detectedAttributes' => [], 'unsupportedInlineCode' => [], 'securityWarnings' => [$exception->getMessage()]];
        }
        if ($vast !== null) {
            return [
                'safe' => true,
                'recipe' => ['executionMode' => 'STRUCTURED', 'provider' => 'HORUS_VAST', 'vastOrigin' => $vast['origin']],
                'detectedScripts' => [],
                'detectedContainers' => [],
                'detectedPublicIdentifiers' => [],
                'detectedAttributes' => [],
                'unsupportedInlineCode' => [],
                'securityWarnings' => [],
            ];
        }

        $parsed = (new DirectTagRecipeParser())->parse($tag);
        $warnings = array_values(array_unique((array) ($parsed['securityWarnings'] ?? [])));
        $gpt = null;
        if (! (bool) ($parsed['containsSensitiveMaterial'] ?? false)) {
            try { $this->assertSafeCustomHtml($tag); $gpt = (new GoogleGptManualTagParser())->parse($tag); }
            catch (RuntimeException $exception) { $warnings[] = $exception->getMessage(); }
        }
        foreach ((array) ($parsed['detectedScripts'] ?? []) as $script) {
            try { $this->assertAllowedScriptUrl((string) ($script['url'] ?? '')); }
            catch (RuntimeException $exception) { $warnings[] = $exception->getMessage(); }
        }
        $warnings = array_values(array_unique($warnings));
        $recipe = $warnings === [] ? ($gpt ? ['executionMode' => 'STRUCTURED', 'provider' => 'GOOGLE_GPT', 'slot' => $gpt] : ['executionMode' => 'STRUCTURED', 'provider' => 'HORUS_ISOLATED']) : null;
        return ['safe' => ! (bool) ($parsed['containsSensitiveMaterial'] ?? false) && $warnings === [], 'recipe' => $recipe, 'detectedScripts' => $parsed['detectedScripts'] ?? [], 'detectedContainers' => $parsed['detectedContainers'] ?? [], 'detectedPublicIdentifiers' => $parsed['detectedPublicIdentifiers'] ?? [], 'detectedAttributes' => data_get($parsed, 'detectedContainers.0.attributes', []), 'unsupportedInlineCode' => [], 'securityWarnings' => $warnings];
    }

    public function generateDirectTag(DemandPlacement $placement): array
    {
        $widget = $this->widget($placement);
        $configuration = $this->mergedConfiguration($placement, $widget);
        $quickManaged = $this->isQuickManaged($placement, $widget);
        if (! $quickManaged && is_array($configuration['direct_recipe'] ?? null)) return parent::generateDirectTag($placement);
        if (! $widget?->direct_tag_template) return parent::generateDirectTag($placement);

        $html = trim((string) $widget->direct_tag_template);
        $vast = app(VastTagUrlParser::class)->parse($html);
        if ($vast !== null) return $this->vastRecipe($vast, $configuration, $placement);

        $this->assertSafeCustomHtml($html);
        if ($quickManaged) $this->assertNoQuickSelfNavigation($html);
        if ($quickManaged) {
            $gpt = (new GoogleGptManualTagParser())->parse($html);
            if ($gpt !== null) {
                foreach ($this->externalScriptUrls($html) as $url) $this->assertAllowedScriptUrl($url);
                return $this->googleGptRecipe($gpt, $configuration, $placement);
            }
        }

        $origins = $this->isolationOrigins($configuration, $quickManaged);
        if ($origins === []) throw new RuntimeException('Custom isolated tags require at least one reviewed provider resource origin.');
        foreach ($this->externalScriptUrls($html) as $url) {
            if ($quickManaged) $this->assertAllowedScriptUrl($url); else $this->assertAllowedLegacyScriptUrl($url);
        }
        if (! $quickManaged) return $this->legacyIsolatedRecipe($html, $origins, $configuration, $placement);
        return $this->isolatedRuntimeRecipe($html, $origins, $configuration, $placement);
    }

    public function generateGamCreative(DemandPlacement $placement): array
    {
        $widget = $this->widget($placement);
        $configuration = $this->mergedConfiguration($placement, $widget);
        $quickManaged = $this->isQuickManaged($placement, $widget);
        if (! $quickManaged && is_array($configuration['direct_recipe'] ?? null)) return parent::generateGamCreative($placement);
        $snippet = trim((string) ($widget?->gam_creative_template ?: $widget?->direct_tag_template));
        if ($snippet === '') return parent::generateGamCreative($placement);
        $this->assertSafeCustomHtml($snippet);
        if ($quickManaged) $this->assertNoQuickSelfNavigation($snippet);
        foreach ($this->externalScriptUrls($snippet) as $url) {
            if ($quickManaged) $this->assertAllowedScriptUrl($url); else $this->assertAllowedLegacyScriptUrl($url);
        }
        $size = $placement->placement->sizes->where('is_active', true)->first(fn ($candidate) => $candidate->size_type === 'FIXED' && $candidate->width && $candidate->height);
        return ['name' => $this->code().' - '.$placement->placement->name, 'creativeType' => 'THIRD_PARTY', 'size' => $size ? ['width' => (int) $size->width, 'height' => (int) $size->height] : ['width' => 1, 'height' => 1], 'snippet' => $snippet, 'safeFrameCompatible' => true];
    }

    private function googleGptRecipe(array $gpt, array $configuration, DemandPlacement $placement): array
    {
        $runtimeUrl = $this->trustedRuntimeUrl('hm-gpt-direct.js');
        $providerContainerId = $gpt['containerId'];
        $containerId = 'hm-gpt-'.$placement->id;
        $sizes = $gpt['sizes'];
        $placementSizes = $this->placementSizes($placement);
        if ($placementSizes === []) throw new RuntimeException('The selected Horus placement has no active fixed or fluid display size for Google GPT.');
        $allowedSizeKeys = collect($placementSizes)
            ->mapWithKeys(fn ($allowed): array => [$this->gptSizeKey($allowed) => true]);
        foreach ($sizes as $size) {
            if (! $allowedSizeKeys->has($this->gptSizeKey($size))) {
                $label = $size === 'fluid' ? 'fluid' : implode('x', $size);
                throw new RuntimeException('The Google GPT slot size '.$label.' is not enabled on the selected Horus placement.');
            }
        }
        $allowedFormats = in_array('fluid', $sizes, true) ? ['DISPLAY', 'NATIVE'] : ['DISPLAY'];
        // GPT can be delayed by publisher-page work, consent/gating and an
        // already-loaded page GPT instance. Terminal GPT events now end the wait
        // immediately, so a longer ceiling protects legitimate late renders
        // without making true no-fill slow.
        $timeout = max(15000, min(30000, (int) ($configuration['render_timeout_ms'] ?? 15000)));
        $successSelector = '#'.$containerId.'[data-hm-gpt-status="rendered"]';
        $attributes = ['data-hm-gpt-direct' => '1', 'data-hm-gpt-ad-unit-path' => $gpt['adUnitPath'], 'data-hm-gpt-sizes' => json_encode($sizes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'data-hm-gpt-inner-id' => $providerContainerId];
        return ['recipeVersion' => 1, 'executionMode' => 'STRUCTURED', 'format' => 'DISPLAY', 'scripts' => [['url' => $runtimeUrl, 'async' => true, 'defer' => false, 'dedupeKey' => 'horus-google-gpt-direct-runtime-v1', 'attributes' => []]], 'container' => ['element' => 'div', 'id' => $containerId, 'class' => 'hm-direct-google-gpt', 'attributes' => $attributes], 'publicPlacementId' => $gpt['adUnitPath'], 'initialization' => ['type' => 'NONE', 'parameters' => []], 'render' => ['timeoutMs' => $timeout, 'successSelector' => $successSelector, 'assumeLoadedIsSuccess' => false, 'allowedFormats' => $allowedFormats, 'allowedSizes' => $sizes], 'isolation' => null, 'scriptUrl' => $runtimeUrl, 'containerId' => $containerId, 'containerClass' => 'hm-direct-google-gpt', 'attributes' => $attributes, 'renderTimeoutMs' => $timeout, 'successSelector' => $successSelector, 'assumeLoadedIsSuccess' => false];
    }

    private function isolatedRuntimeRecipe(string $html, array $origins, array $configuration, DemandPlacement $placement): array
    {
        $policy = $this->quickPlacementSizePolicy($placement);
        $frameSize = $policy['fallback'];
        $sizes = $policy['sizes'];
        $scriptOrigins = collect($this->externalScriptUrls($html))->map(fn ($url) => $this->canonicalHttpsOrigin((string) $url))->filter()->unique()->values()->all();
        $fallbackResources = array_values(array_diff($origins, $scriptOrigins));
        $frameOrigins = $this->quickDirectiveOrigins($configuration, 'isolation_frame_origins', $fallbackResources);
        $imageOrigins = $this->quickDirectiveOrigins($configuration, 'isolation_image_origins', $fallbackResources);
        $styleOrigins = $this->quickDirectiveOrigins($configuration, 'isolation_style_origins', $fallbackResources);
        $mediaOrigins = $this->quickDirectiveOrigins($configuration, 'isolation_media_origins', $fallbackResources);
        $fontOrigins = $this->quickDirectiveOrigins($configuration, 'isolation_font_origins', $fallbackResources);
        $csp = $this->isolatedCsp($scriptOrigins, $origins, $frameOrigins, $imageOrigins, $styleOrigins, $mediaOrigins, $fontOrigins);
        $runtimeUrl = $this->trustedRuntimeUrl('hm-isolated-direct.js');
        $containerId = 'hm-isolated-'.$placement->id;
        $timeout = max(500, min(10000, (int) ($configuration['render_timeout_ms'] ?? config('demand.direct_render_timeout_ms', 2500))));
        $format = strtoupper((string) ($configuration['format'] ?? $placement->placement->type->value));
        $attributes = ['data-hm-isolated-direct' => '1', 'data-hm-isolated-width' => (string) $frameSize[0], 'data-hm-isolated-height' => (string) $frameSize[1], 'data-hm-isolated-sizes' => json_encode($sizes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'data-hm-isolated-size-map' => json_encode($policy['mappings'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
        $attributes += $this->encodedPayloadAttributes('data-hm-isolated-html', $html);
        $attributes += $this->encodedPayloadAttributes('data-hm-isolated-csp', $csp);
        $successSelector = '#'.$containerId.'[data-hm-isolated-status="rendered"]';
        return ['recipeVersion' => 1, 'executionMode' => 'STRUCTURED', 'format' => $format, 'scripts' => [['url' => $runtimeUrl, 'async' => true, 'defer' => false, 'dedupeKey' => 'horus-isolated-direct-runtime-v1', 'attributes' => []]], 'container' => ['element' => 'div', 'id' => $containerId, 'class' => 'hm-direct-demand-isolated', 'attributes' => $attributes], 'publicPlacementId' => (string) ($placement->remote_placement_id ?? $placement->placement_code ?? $placement->id), 'initialization' => ['type' => 'NONE', 'parameters' => []], 'render' => ['timeoutMs' => $timeout, 'successSelector' => $successSelector, 'assumeLoadedIsSuccess' => false, 'allowedFormats' => [$format], 'allowedSizes' => $sizes], 'isolation' => null, 'scriptUrl' => $runtimeUrl, 'containerId' => $containerId, 'containerClass' => 'hm-direct-demand-isolated', 'attributes' => $attributes, 'renderTimeoutMs' => $timeout, 'successSelector' => $successSelector, 'assumeLoadedIsSuccess' => false];
    }

    /** @param array{url:string,origin:string} $vast */
    private function vastRecipe(array $vast, array $configuration, DemandPlacement $placement): array
    {
        if ($placement->placement->type->value !== 'VIDEO') {
            throw new RuntimeException('A VAST URL requires a Horus Video placement such as Floating Video or Outstream / In-read Video.');
        }

        $policy = $this->quickPlacementSizePolicy($placement);
        $frameSize = $policy['fallback'];
        $sizes = $policy['sizes'];
        $runtimeUrl = $this->trustedRuntimeUrl('hm-video-direct.js');
        $containerId = 'hm-video-'.$placement->id;
        $timeout = max(15_000, min(30_000, (int) ($configuration['render_timeout_ms'] ?? 20_000)));
        $attributes = [
            'data-hm-video-direct' => '1',
            'data-hm-vast-url' => base64_encode($vast['url']),
            'data-hm-video-width' => (string) $frameSize[0],
            'data-hm-video-height' => (string) $frameSize[1],
            'data-hm-video-sizes' => json_encode($sizes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'data-hm-video-size-map' => json_encode($policy['mappings'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'data-hm-video-muted' => '1',
            'data-hm-video-autoplay' => '1',
        ];
        $successSelector = '#'.$containerId.'[data-hm-video-status="started"]';

        return [
            'recipeVersion' => 1,
            'executionMode' => 'STRUCTURED',
            'format' => 'VIDEO',
            'scripts' => [[
                'url' => $runtimeUrl,
                'async' => true,
                'defer' => false,
                'dedupeKey' => 'horus-video-direct-runtime-v1',
                'attributes' => [],
            ]],
            'container' => ['element' => 'div', 'id' => $containerId, 'class' => 'hm-direct-video', 'attributes' => $attributes],
            'publicPlacementId' => (string) ($placement->remote_placement_id ?? $placement->placement_code ?? $placement->id),
            'initialization' => ['type' => 'NONE', 'parameters' => []],
            'render' => ['timeoutMs' => $timeout, 'successSelector' => $successSelector, 'assumeLoadedIsSuccess' => false, 'allowedFormats' => ['VIDEO', 'OUTSTREAM'], 'allowedSizes' => $sizes],
            'isolation' => null,
            'scriptUrl' => $runtimeUrl,
            'containerId' => $containerId,
            'containerClass' => 'hm-direct-video',
            'attributes' => $attributes,
            'renderTimeoutMs' => $timeout,
            'successSelector' => $successSelector,
            'assumeLoadedIsSuccess' => false,
        ];
    }

    private function legacyIsolatedRecipe(string $html, array $origins, array $configuration, DemandPlacement $placement): array
    {
        $originList = implode(' ', $origins);
        $format = strtoupper((string) ($configuration['format'] ?? $placement->placement->type->value));
        $timeout = max(500, min(10000, (int) ($configuration['render_timeout_ms'] ?? config('demand.direct_render_timeout_ms', 2500))));
        $containerId = 'hm-isolated-'.$placement->id;
        return ['recipeVersion' => 1, 'executionMode' => 'ISOLATED_IFRAME', 'format' => $format, 'scripts' => [], 'container' => ['element' => 'div', 'id' => $containerId, 'class' => 'hm-direct-demand-isolated', 'attributes' => []], 'publicPlacementId' => (string) ($placement->remote_placement_id ?? $placement->placement_code ?? $placement->id), 'initialization' => ['type' => 'NONE', 'parameters' => []], 'render' => ['timeoutMs' => $timeout, 'successSelector' => null, 'assumeLoadedIsSuccess' => true, 'allowedFormats' => [$format], 'allowedSizes' => []], 'isolation' => ['html' => $html, 'csp' => $this->legacyIsolatedCsp($originList), 'sandbox' => ['allow-scripts']], 'scriptUrl' => '', 'containerId' => $containerId, 'containerClass' => 'hm-direct-demand-isolated', 'attributes' => [], 'renderTimeoutMs' => $timeout, 'successSelector' => null, 'assumeLoadedIsSuccess' => true];
    }

    private function isolatedCsp(array $scriptOrigins, array $allOrigins, array $frameOrigins, array $imageOrigins, array $styleOrigins, array $mediaOrigins, array $fontOrigins): string
    {
        $scripts = $this->cspSources($scriptOrigins); $connect = $this->cspSources($allOrigins); $frames = $this->cspSources(array_merge($scriptOrigins, $frameOrigins)); $images = $this->cspSources(array_merge($scriptOrigins, $imageOrigins)); $styles = $this->cspSources(array_merge($scriptOrigins, $styleOrigins)); $media = $this->cspSources(array_merge($scriptOrigins, $mediaOrigins)); $fonts = $this->cspSources(array_merge($scriptOrigins, $fontOrigins));
        return "default-src 'none'; script-src 'unsafe-inline'{$this->cspSuffix($scripts)}; connect-src{$this->cspSuffix($connect)}; img-src{$this->cspSuffix($images)} data:; style-src 'unsafe-inline'{$this->cspSuffix($styles)}; frame-src{$this->cspSuffix($frames)}; font-src{$this->cspSuffix($fonts)} data:; media-src{$this->cspSuffix($media)}; base-uri 'none'; form-action 'none'; object-src 'none';";
    }

    private function legacyIsolatedCsp(string $originList): string { return "default-src 'none'; script-src 'unsafe-inline' {$originList}; connect-src {$originList}; img-src https: data:; style-src 'unsafe-inline'; frame-src {$originList};"; }
    private function cspSources(array $origins): string { return collect($origins)->filter(fn ($value) => is_string($value) && $value !== '')->unique()->values()->implode(' '); }
    private function cspSuffix(string $sources): string { return $sources === '' ? '' : ' '.$sources; }
    private function quickDirectiveOrigins(array $configuration, string $key, array $fallback): array { if (! array_key_exists($key, $configuration)) return array_values(array_unique($fallback)); return collect((array) $configuration[$key])->map(fn ($origin) => $this->canonicalHttpsOrigin((string) $origin))->filter()->unique()->take(20)->values()->all(); }

    private function encodedPayloadAttributes(string $baseAttribute, string $payload): array
    {
        $encoded = base64_encode($payload);
        if (strlen($encoded) <= 1800) return [$baseAttribute => $encoded];
        $parts = str_split($encoded, 1800);
        if (count($parts) > 64) throw new RuntimeException('The encoded isolated tag exceeds the trusted runtime payload limit.');
        $attributes = [$baseAttribute.'-parts' => (string) count($parts)];
        foreach ($parts as $index => $part) $attributes[$baseAttribute.'-'.$index] = $part;
        return $attributes;
    }

    private function isQuickManaged(DemandPlacement $placement, ?DemandWidget $widget): bool { return (bool) data_get($placement->configuration, 'quick_monetize_managed', false) || (bool) data_get($widget?->configuration, 'quick_monetize_managed', false); }

    private function quickPlacementSizePolicy(DemandPlacement $placement): array
    {
        $placement->loadMissing('placement.sizes');
        $active = $placement->placement->sizes->where('is_active', true)->filter(fn ($size) => $size->size_type === 'FIXED' && $size->width && $size->height)->values();
        $sizes = $active->map(fn ($size): array => [(int) $size->width, (int) $size->height])->unique(fn (array $size): string => $size[0].'x'.$size[1])->take(20)->values()->all();
        if ($sizes === []) throw new RuntimeException('Quick Monetize generic tags require at least one active fixed size. Fluid or provider-managed surfaces require a dedicated provider adapter or Advanced setup.');
        $allowed = collect($sizes)->mapWithKeys(fn (array $size): array => [$size[0].'x'.$size[1] => true]);
        $mappings = $active->filter(fn ($size) => (int) $size->min_viewport_width > 0 || (int) $size->min_viewport_height > 0 || $size->max_viewport_width !== null || $size->max_viewport_height !== null || $size->device->value !== 'ALL')->map(fn ($size): array => ['minWidth' => (int) $size->min_viewport_width, 'minHeight' => (int) $size->min_viewport_height, 'maxWidth' => $size->max_viewport_width !== null ? (int) $size->max_viewport_width : null, 'maxHeight' => $size->max_viewport_height !== null ? (int) $size->max_viewport_height : null, 'width' => (int) $size->width, 'height' => (int) $size->height, 'priority' => (int) $size->priority])->filter(fn (array $mapping): bool => $allowed->has($mapping['width'].'x'.$mapping['height']))->sort(function (array $left, array $right): int { $min = $right['minWidth'] <=> $left['minWidth']; return $min !== 0 ? $min : ($left['priority'] <=> $right['priority']); })->values()->all();
        return ['fallback' => $sizes[0], 'sizes' => $sizes, 'mappings' => $mappings];
    }

    private function trustedRuntimeUrl(string $asset): string
    {
        $base = rtrim((string) config('horus.cdn_url'), '/'); if ($base === '') $base = 'https://cdn.horusmedia.net';
        $assetPath = public_path('assets/'.$asset); $contents = is_file($assetPath) ? file_get_contents($assetPath) : false;
        if ($contents === false || $contents === '') throw new RuntimeException('Horus Direct Demand runtime asset is missing.');
        $hash = substr(hash('sha256', $contents), 0, 16);
        $runtimePath = match ($asset) {
            'hm-gpt-direct.js' => 'runtime/gpt/hm-gpt-direct.'.$hash.'.js',
            'hm-isolated-direct.js' => 'runtime/direct/hm-isolated-direct.'.$hash.'.js',
            'hm-video-direct.js' => 'runtime/video/hm-video-direct.'.$hash.'.js',
            default => throw new RuntimeException('Unknown Horus Direct Demand runtime asset.'),
        };
        $url = $base.'/'.$runtimePath;
        if (! filter_var($url, FILTER_VALIDATE_URL) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' || (string) parse_url($url, PHP_URL_HOST) === '') throw new RuntimeException('Horus Direct Demand runtime requires a trusted HTTPS CDN URL.');
        return $url;
    }

    protected function assertAllowedScriptUrl(string $url): void
    {
        $origin = $this->canonicalHttpsOrigin($url);
        if ($origin === null) { $host = app(PublicProviderOriginValidator::class)->normalizeHost((string) parse_url($url, PHP_URL_HOST)); throw new RuntimeException("Demand script host [{$host}] is private, reserved, unresolved, control-plane, or otherwise not valid for publisher delivery."); }
        $allowed = collect($this->selectedAccount->network->script_origins ?? [])->merge((array) config('demand.allowed_script_origins.'.$this->code(), []))->merge((array) data_get($this->selectedAccount->configuration, 'allowed_script_origins', []))->map(fn ($value) => $this->canonicalHttpsOrigin((string) $value))->filter()->unique();
        if ($allowed->isEmpty() || ! $allowed->contains($origin)) throw new RuntimeException("Demand script origin [{$origin}] is not allowlisted for ".$this->code().'.');
    }

    private function assertAllowedLegacyScriptUrl(string $url): void
    {
        $origin = $this->legacyHttpsOrigin($url); if ($origin === null) throw new RuntimeException('Legacy demand script URL is not a valid reviewed HTTPS provider origin.');
        $allowed = collect($this->selectedAccount->network->script_origins ?? [])->merge((array) config('demand.allowed_script_origins.'.$this->code(), []))->merge((array) data_get($this->selectedAccount->configuration, 'allowed_script_origins', []))->map(fn ($value) => $this->legacyHttpsOrigin((string) $value))->filter()->unique();
        if ($allowed->isEmpty() || ! $allowed->contains($origin)) throw new RuntimeException("Demand script origin [{$origin}] is not allowlisted for ".$this->code().'.');
    }

    private function canonicalHttpsOrigin(string $url): ?string { return app(PublicProviderOriginValidator::class)->canonicalOrigin($url); }

    private function legacyHttpsOrigin(string $url): ?string
    {
        $url = trim($url); if (str_starts_with($url, '//')) $url = 'https:'.$url;
        if (! filter_var($url, FILTER_VALIDATE_URL) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) return null;
        $validator = app(PublicProviderOriginValidator::class); $host = $validator->normalizeHost((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || $host === 'localhost' || $host === 'localhost.localdomain' || $host === 'app.horusmedia.net' || str_ends_with($host, '.app.horusmedia.net')) return null;
        foreach (['.localhost', '.local', '.internal', '.home.arpa', '.test', '.invalid', '.example'] as $suffix) if (str_ends_with($host, $suffix)) return null;
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) return $this->canonicalHttpsOrigin($url);
        if (! str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) return null;
        $originHost = str_contains($host, ':') ? '['.$host.']' : $host; $port = parse_url($url, PHP_URL_PORT);
        return 'https://'.$originHost.($port && (int) $port !== 443 ? ':'.(int) $port : '');
    }

    private function assertSafeCustomHtml(string $html): void
    {
        if ($html === '' || strlen($html) > 60_000) throw new RuntimeException('The configured third-party creative is empty or exceeds the safe size limit.');
        $unsafe = ['/document\s*\.\s*cookie/i', '/(?:local|session)Storage/i', '/javascript\s*:/i', '/\beval\s*\(/i', '/(?:window\s*\.\s*)?top\s*\.\s*(?:location|document)/i', '/<\s*(?:object|embed|applet|base|meta)\b/i', '/(?:env|file):[A-Za-z0-9_\/.:-]+/i', '/constructor\s*\.\s*constructor/i'];
        foreach ($unsafe as $pattern) if (preg_match($pattern, $html)) throw new RuntimeException('The configured third-party creative contains unsafe or private content.');
        if (preg_match('/\bFunction\s*\(/', $html) || preg_match('/["\']Function["\']\s*\]/', $html)) throw new RuntimeException('The configured third-party creative contains a dynamic Function constructor.');
        if (preg_match('/(?:src|href)\s*=\s*(["\'])http:\/\//i', $html)) throw new RuntimeException('Third-party creative assets must use HTTPS.');
    }

    private function assertNoQuickSelfNavigation(string $html): void
    {
        $receiver = '(?:window|self|document|globalThis)'; $location = '(?:\.\s*location|\[\s*["\']location["\']\s*\])'; $href = '(?:\.\s*href|\[\s*["\']href["\']\s*\])'; $navigationMethod = '(?:\.\s*(?:assign|replace)|\[\s*["\'](?:assign|replace)["\']\s*\])';
        $patterns = ['/\b'.$receiver.'\s*'.$location.'\s*(?:'.$href.'\s*)?=/i', '/\b'.$receiver.'\s*'.$location.'\s*'.$navigationMethod.'\s*\(/i', '/(?<![\w.$])location\s*(?:'.$href.'\s*)?=/i', '/(?<![\w.$])location\s*'.$navigationMethod.'\s*\(/i'];
        foreach ($patterns as $pattern) if (preg_match($pattern, $html)) throw new RuntimeException('Quick Monetize isolated tags cannot navigate their sandbox document. Use Advanced setup for navigation-capable provider code.');
    }

    private function externalScriptUrls(string $html): array { $parsed = (new DirectTagRecipeParser())->parse($html); return collect((array) ($parsed['detectedScripts'] ?? []))->map(fn ($script): string => trim((string) ($script['url'] ?? '')))->filter()->unique()->values()->all(); }

    /** @return array<int, array{0:int,1:int}|string> */
    private function placementSizes(DemandPlacement $placement): array
    {
        $placement->loadMissing('placement.sizes');
        $active = $placement->placement->sizes->where('is_active', true);
        $sizes = $active
            ->filter(fn ($size) => $size->size_type === 'FIXED' && $size->width && $size->height)
            ->map(fn ($size): array => [(int) $size->width, (int) $size->height])
            ->unique(fn (array $size): string => $size[0].'x'.$size[1])
            ->values()
            ->all();

        if ($active->contains(fn ($size): bool => $size->size_type === 'FLUID')) {
            $sizes[] = 'fluid';
        }

        return $sizes;
    }

    private function gptSizeKey(mixed $size): string
    {
        return $size === 'fluid'
            ? 'fluid'
            : (is_array($size) && count($size) === 2 ? ((int) $size[0]).'x'.((int) $size[1]) : 'invalid');
    }

    private function isolationOrigins(array $configuration, bool $strictDns): array { return collect((array) ($configuration['isolation_allowed_origins'] ?? []))->map(fn ($origin) => $strictDns ? $this->canonicalHttpsOrigin((string) $origin) : $this->legacyHttpsOrigin((string) $origin))->filter()->unique()->take(20)->values()->all(); }
}
