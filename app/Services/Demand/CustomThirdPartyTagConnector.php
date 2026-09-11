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
        if (! $widget?->direct_tag_template) {
            return parent::generateDirectTag($placement);
        }

        $html = trim((string) $widget->direct_tag_template);
        $configuration = $this->mergedConfiguration($placement, $widget);
        $this->assertSafeCustomHtml($html);

        $gpt = (new GoogleGptManualTagParser())->parse($html);
        if ($gpt !== null) {
            foreach ($this->externalScriptUrls($html) as $url) {
                $this->assertAllowedScriptUrl($url);
            }

            return $this->googleGptRecipe($gpt, $configuration);
        }

        $origins = $this->isolationOrigins($configuration);
        if ($origins === []) {
            throw new RuntimeException('Custom isolated tags require explicit provider CSP origins.');
        }

        foreach ($this->externalScriptUrls($html) as $url) {
            $this->assertAllowedScriptUrl($url);
        }

        $originList = implode(' ', $origins);
        $csp = "default-src 'none'; script-src 'unsafe-inline' {$originList}; connect-src https:; img-src https: data:; style-src 'unsafe-inline'; frame-src https:; font-src https: data:; media-src https:; base-uri 'none'; form-action 'none';";
        $format = strtoupper((string) ($configuration['format'] ?? $placement->placement->type->value));
        $timeout = max(500, min(10000, (int) ($configuration['render_timeout_ms'] ?? config('demand.direct_render_timeout_ms', 2500))));

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
                'allowedSizes' => [],
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
     * A small Horus-owned runtime creates an independent GPT frame for the slot,
     * avoiding both arbitrary inline execution and top-page GPT state conflicts.
     *
     * @param array{adUnitPath:string,containerId:string,sizes:array<int,array{0:int,1:int}>} $gpt
     * @return array<string, mixed>
     */
    private function googleGptRecipe(array $gpt, array $configuration): array
    {
        $runtimeUrl = $this->trustedGptRuntimeUrl();
        $containerId = $gpt['containerId'];
        $sizes = $gpt['sizes'];
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
            ->map(fn ($url) => html_entity_decode(trim((string) $url), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            ->filter()
            ->unique()
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
                $host = strtolower((string) parse_url($origin, PHP_URL_HOST));

                return $host !== ''
                    && $host !== 'app.horusmedia.net'
                    && ! str_ends_with($host, '.app.horusmedia.net');
            })
            ->unique()
            ->take(20)
            ->values()
            ->all();
    }
}
