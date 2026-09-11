<?php

namespace App\Services\Demand;

use App\Models\DemandPlacement;
use RuntimeException;

final class CustomThirdPartyTagConnector extends AbstractDemandConnector
{
    protected function code(): string
    {
        return 'CUSTOM_THIRD_PARTY_TAG';
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
                : ['executionMode' => 'ISOLATED_IFRAME'];
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

        $gpt = (new GoogleGptManualTagParser())->parse($html);
        if ($gpt !== null) {
            foreach ($this->externalScriptUrls($html) as $url) {
                $this->assertAllowedScriptUrl($url);
            }

            return $this->googleGptRecipe($gpt, $configuration, $placement);
        }

        $origins = $this->isolationOrigins($configuration);
        if ($origins === []) {
            throw new RuntimeException('Custom isolated tags require explicit provider CSP origins.');
        }

        foreach ($this->externalScriptUrls($html) as $url) {
            $this->assertAllowedScriptUrl($url);
        }

        $originList = implode(' ', $origins);
        $csp = "default-src 'none'; script-src 'unsafe-inline' {$originList}; connect-src {$originList}; img-src {$originList} data:; style-src 'unsafe-inline'; frame-src {$originList}; font-src {$originList} data:; media-src {$originList}; base-uri 'none'; form-action 'none'; object-src 'none';";
        $format = strtoupper((string) ($configuration['format'] ?? $placement->placement->type->value));
        $timeout = max(500, min(10000, (int) ($configuration['render_timeout_ms'] ?? config('demand.direct_render_timeout_ms', 2500))));
        $sizes = $this->placementSizes($placement);

        return [
            'recipeVersion' => 1,
            'executionMode' => 'ISOLATED_IFRAME',
            'format' => $format,
            'scripts' => [],
            'container' => [
                'element' => 'div',
                'id' => 'hm-isolated-'.$placement->id,
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
                'allowedSizes' => $sizes,
            ],
            'isolation' => [
                'html' => $html,
                'csp' => $csp,
                'sandbox' => ['allow-scripts'],
            ],
            'scriptUrl' => '',
            'containerId' => 'hm-isolated-'.$placement->id,
            'containerClass' => 'hm-direct-demand-isolated',
            'attributes' => [],
            'renderTimeoutMs' => $timeout,
            'successSelector' => null,
            'assumeLoadedIsSuccess' => true,
        ];
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
        $runtimeUrl = $this->trustedGptRuntimeUrl();
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

    private function trustedGptRuntimeUrl(): string
    {
        $base = rtrim((string) config('horus.cdn_url'), '/');
        if ($base === '') {
            $base = 'https://cdn.horusmedia.net';
        }
        $url = $base.'/assets/hm-gpt-direct.js';
        if (! filter_var($url, FILTER_VALIDATE_URL)
            || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
            || (string) parse_url($url, PHP_URL_HOST) === '') {
            throw new RuntimeException('Horus GPT direct runtime requires a trusted HTTPS CDN URL.');
        }

        return $url;
    }

    protected function assertAllowedScriptUrl(string $url): void
    {
        parent::assertAllowedScriptUrl($url);

        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if (! $this->isPublicScriptHost($host)) {
            throw new RuntimeException("Demand script host [{$host}] is private, reserved, or otherwise not valid for publisher delivery.");
        }
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
