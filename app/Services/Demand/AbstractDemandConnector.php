<?php

namespace App\Services\Demand;

use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Models\DemandAccount;
use App\Models\DemandPlacement;
use App\Models\DemandSite;
use App\Models\DemandWidget;
use App\Services\Demand\Contracts\DemandConnectorInterface;
use App\Services\Demand\Data\DemandResult;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

abstract class AbstractDemandConnector implements DemandConnectorInterface
{
    public function __construct(
        protected readonly DemandAccount $selectedAccount,
        protected readonly DemandSecretResolver $secrets,
    ) {
    }

    public function account(): DemandAccount
    {
        return $this->selectedAccount;
    }

    abstract protected function code(): string;

    public function validateConfiguration(array $configuration = []): array
    {
        $configuration = $configuration ?: ($this->selectedAccount->configuration ?? []);
        $errors = [];

        foreach (array_keys($configuration) as $key) {
            if (preg_match('/secret|password|private[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret/i', (string) $key)
                && ! str_ends_with(strtolower((string) $key), '_credential_key')) {
                $errors[] = "Secret-like configuration key [{$key}] must be stored as an encrypted credential reference.";
            }
        }

        $mode = $this->selectedAccount->integration_mode;
        $network = $this->selectedAccount->network;

        if ($mode === DemandIntegrationMode::DirectJs && ! $network->supports_direct_js) {
            $errors[] = 'The selected network does not support DIRECT_JS.';
        }
        if ($mode === DemandIntegrationMode::GamThirdPartyCreative && ! $network->supports_gam_creative) {
            $errors[] = 'The selected network does not support GAM third-party creatives.';
        }
        if ($mode === DemandIntegrationMode::GamLineItem && ! $network->supports_gam_line_item) {
            $errors[] = 'The selected network does not support GAM line-item deployment.';
        }
        if ($mode === DemandIntegrationMode::ApiIntegration && ! $network->supports_api) {
            $errors[] = 'The selected network does not support API integration.';
        }

        if (! empty($configuration['script_url'])) {
            try {
                $this->assertAllowedScriptUrl((string) $configuration['script_url']);
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        return $errors;
    }

    public function testConnection(array $options = []): DemandResult
    {
        $errors = $this->validateConfiguration();
        if ($errors) {
            return DemandResult::failure('VALIDATION', 'INVALID_CONFIGURATION', implode(' ', $errors));
        }

        $configuration = $this->selectedAccount->configuration ?? [];
        if (empty($configuration['api_base_url']) || empty($configuration['health_path'])) {
            return DemandResult::success([
                'mode' => 'CONFIGURATION_ONLY',
                'message' => 'Configuration is valid. API health testing is not configured for this approved account.',
            ]);
        }

        if (($options['dry_run'] ?? false) === true) {
            return DemandResult::dryRun([
                'method' => 'GET',
                'url' => $this->apiUrl((string) $configuration['health_path']),
            ]);
        }

        return $this->request('GET', (string) $configuration['health_path']);
    }

    public function createSite(DemandSite $site, array $options = []): DemandResult
    {
        if ($site->remote_site_id) {
            return DemandResult::success(['id' => $site->remote_site_id, 'existing' => true]);
        }

        $configuration = $this->selectedAccount->configuration ?? [];
        if (empty($configuration['create_site_path'])) {
            return DemandResult::failure(
                'CONFIGURATION',
                'MANUAL_SITE_MAPPING_REQUIRED',
                'The provider requires a remote site ID or an approved create-site API path.'
            );
        }

        $payload = [
            'name' => $site->site->display_name,
            'domain' => $site->site->primary_domain,
            'language' => $site->site->language,
            'country' => $site->site->country,
        ];

        if (($options['dry_run'] ?? false) === true) {
            return DemandResult::dryRun(['method' => 'POST', 'path' => $configuration['create_site_path'], 'payload' => $payload]);
        }

        return $this->request(
            'POST',
            (string) $configuration['create_site_path'],
            $payload,
            false,
            $this->idempotencyHeaders($options, 'site:'.$site->id),
        );
    }

    public function getSiteStatus(DemandSite $site, array $options = []): DemandResult
    {
        $configuration = $this->selectedAccount->configuration ?? [];
        if (! $site->remote_site_id || empty($configuration['site_status_path'])) {
            return DemandResult::success([
                'remote_site_id' => $site->remote_site_id,
                'status' => $site->approval_status->value,
                'source' => 'LOCAL_MAPPING',
            ]);
        }

        $path = str_replace('{site_id}', rawurlencode($site->remote_site_id), (string) $configuration['site_status_path']);

        return $this->request('GET', $path);
    }

    public function createPlacement(DemandPlacement $placement, array $options = []): DemandResult
    {
        if ($placement->remote_placement_id) {
            return DemandResult::success(['id' => $placement->remote_placement_id, 'existing' => true]);
        }

        $configuration = $this->selectedAccount->configuration ?? [];
        if (empty($configuration['create_placement_path'])) {
            return DemandResult::failure(
                'CONFIGURATION',
                'MANUAL_PLACEMENT_MAPPING_REQUIRED',
                'The provider requires a remote placement/widget ID or an approved create-placement API path.'
            );
        }

        $payload = [
            'site_id' => $placement->demandSite->remote_site_id,
            'name' => $placement->placement->name,
            'code' => $placement->placement->code,
            'sizes' => $placement->placement->sizes
                ->where('is_active', true)
                ->map(fn ($size) => $size->size_type === 'FLUID' ? 'fluid' : $size->width.'x'.$size->height)
                ->values()
                ->all(),
        ];

        if (($options['dry_run'] ?? false) === true) {
            return DemandResult::dryRun(['method' => 'POST', 'path' => $configuration['create_placement_path'], 'payload' => $payload]);
        }

        return $this->request(
            'POST',
            (string) $configuration['create_placement_path'],
            $payload,
            false,
            $this->idempotencyHeaders($options, 'placement:'.$placement->id),
        );
    }

    public function getPlacementCode(DemandPlacement $placement, array $options = []): DemandResult
    {
        if ($placement->placement_code) {
            return DemandResult::success(['code' => $placement->placement_code, 'source' => 'LOCAL_MAPPING']);
        }

        $widget = $this->widget($placement);
        if ($widget?->widget_code) {
            return DemandResult::success(['code' => $widget->widget_code, 'source' => 'WIDGET_MAPPING']);
        }

        return DemandResult::failure('CONFIGURATION', 'PLACEMENT_CODE_MISSING', 'No approved placement or widget code is configured.');
    }

    public function parseDirectTag(string $tag): array
    {
        $parsed = (new DirectTagRecipeParser())->parse($tag);
        $warnings = (array) ($parsed['securityWarnings'] ?? []);

        if ((bool) ($parsed['containsSensitiveMaterial'] ?? false)) {
            return $this->tagReview($parsed, null, array_values(array_unique($warnings)));
        }

        $scripts = (array) ($parsed['detectedScripts'] ?? []);
        $containers = (array) ($parsed['detectedContainers'] ?? []);
        foreach ($scripts as $script) {
            try {
                $this->assertAllowedScriptUrl((string) ($script['url'] ?? ''));
            } catch (Throwable $exception) {
                $warnings[] = $exception->getMessage();
            }
        }
        if (count($containers) !== 1) {
            $warnings[] = 'A structured Direct Demand tag must resolve to exactly one render container.';
        }

        try {
            $initialization = $this->trustedInitializationForParsedTag($parsed);
        } catch (Throwable $exception) {
            $initialization = ['type' => 'NONE', 'parameters' => []];
            $warnings[] = $exception->getMessage();
        }

        if ((array) ($parsed['inlineCode'] ?? []) !== [] && ($initialization['type'] ?? 'NONE') === 'NONE') {
            $warnings[] = 'Unsupported inline JavaScript cannot execute in structured Direct Demand mode.';
        }

        $recipe = null;
        if ($scripts !== [] && count($containers) === 1 && $warnings === []) {
            $container = $containers[0];
            $recipe = [
                'recipeVersion' => 1,
                'executionMode' => 'STRUCTURED',
                'format' => 'DISPLAY',
                'scripts' => collect($scripts)->map(fn (array $script): array => [
                    'url' => $script['url'],
                    'async' => (bool) ($script['async'] ?? true),
                    'defer' => (bool) ($script['defer'] ?? false),
                    'dedupeKey' => hash('sha256', (string) $script['url']),
                    'attributes' => $this->publicAttributes((array) ($script['attributes'] ?? [])),
                ])->values()->all(),
                'container' => [
                    'element' => $container['element'] ?? 'div',
                    'id' => $container['id'] ?? null,
                    'class' => $container['class'] ?? null,
                    'attributes' => $this->publicAttributes((array) ($container['attributes'] ?? [])),
                ],
                'publicPlacementId' => data_get($parsed, 'detectedPublicIdentifiers.0'),
                'initialization' => $initialization,
                'render' => [
                    'timeoutMs' => (int) config('demand.direct_render_timeout_ms', 2500),
                    'successSelector' => null,
                    'assumeLoadedIsSuccess' => false,
                    'allowedFormats' => [],
                    'allowedSizes' => [],
                ],
                'isolation' => null,
            ];
        }

        return $this->tagReview($parsed, $recipe, array_values(array_unique($warnings)));
    }

    public function generateDirectTag(DemandPlacement $placement): array
    {
        $widget = $this->widget($placement);
        $configuration = $this->mergedConfiguration($placement, $widget);

        if (is_array($configuration['direct_recipe'] ?? null)) {
            return $this->normalizeDirectRecipe((array) $configuration['direct_recipe'], $placement);
        }

        if ($this->code() === 'CUSTOM_THIRD_PARTY_TAG' && $widget?->direct_tag_template) {
            return $this->isolatedThirdPartyRecipe($widget->direct_tag_template, $configuration, $placement);
        }

        $configuredScripts = (array) ($configuration['scripts'] ?? []);
        $scriptUrl = trim((string) ($configuration['script_url'] ?? ''));
        if ($configuredScripts === [] && $scriptUrl !== '') {
            $configuredScripts[] = [
                'url' => $scriptUrl,
                'async' => (bool) ($configuration['script_async'] ?? true),
                'defer' => (bool) ($configuration['script_defer'] ?? false),
                'attributes' => (array) ($configuration['script_attributes'] ?? []),
            ];
        }
        if ($configuredScripts === []) {
            throw new RuntimeException($this->code().' direct delivery requires at least one approved external script.');
        }

        $scripts = [];
        foreach ($configuredScripts as $script) {
            $script = is_string($script) ? ['url' => $script] : (array) $script;
            $url = trim((string) ($script['url'] ?? ''));
            $this->assertAllowedScriptUrl($url);
            $scripts[] = [
                'url' => $url,
                'async' => (bool) ($script['async'] ?? true),
                'defer' => (bool) ($script['defer'] ?? false),
                'dedupeKey' => preg_replace('/[^A-Za-z0-9_.:-]/', '-', (string) ($script['dedupe_key'] ?? hash('sha256', $url))),
                'attributes' => $this->publicAttributes((array) ($script['attributes'] ?? [])),
            ];
        }

        $containerId = (string) (
            $configuration['container_id']
            ?? $widget?->widget_code
            ?? $placement->placement_code
            ?? 'hm-direct-'.$placement->id
        );
        $containerId = preg_replace('/[^A-Za-z0-9_:-]/', '-', $containerId);
        $containerClass = (string) ($configuration['container_class'] ?? 'hm-direct-demand-container');
        $attributes = $this->publicAttributes((array) ($configuration['attributes'] ?? []));
        $timeout = max(500, min(10000, (int) ($configuration['render_timeout_ms'] ?? config('demand.direct_render_timeout_ms', 2500))));
        $format = strtoupper((string) ($configuration['format'] ?? $placement->placement->type->value));
        $sizes = $placement->placement->sizes
            ->where('is_active', true)
            ->filter(fn ($size) => $size->size_type === 'FIXED' && $size->width && $size->height)
            ->map(fn ($size) => [(int) $size->width, (int) $size->height])
            ->values()->all();

        return [
            'recipeVersion' => 1,
            'executionMode' => 'STRUCTURED',
            'format' => $format,
            'scripts' => $scripts,
            'container' => [
                'element' => strtolower((string) ($configuration['container_element'] ?? 'div')),
                'id' => $containerId,
                'class' => $containerClass,
                'attributes' => $attributes,
            ],
            'publicPlacementId' => (string) ($configuration['public_placement_id'] ?? $widget?->remote_widget_id ?? $placement->remote_placement_id ?? $placement->placement_code ?? $containerId),
            'initialization' => ['type' => 'NONE', 'parameters' => []],
            'render' => [
                'timeoutMs' => $timeout,
                'successSelector' => isset($configuration['success_selector']) ? (string) $configuration['success_selector'] : null,
                'assumeLoadedIsSuccess' => (bool) ($configuration['assume_loaded_is_success'] ?? false),
                'allowedFormats' => [$format],
                'allowedSizes' => $sizes,
            ],
            'isolation' => null,

            // Legacy flattened tag fields are retained during the schema-v4 rollout.
            'scriptUrl' => $scripts[0]['url'],
            'containerId' => $containerId,
            'containerClass' => $containerClass,
            'attributes' => $attributes,
            'renderTimeoutMs' => $timeout,
            'successSelector' => isset($configuration['success_selector']) ? (string) $configuration['success_selector'] : null,
            'assumeLoadedIsSuccess' => (bool) ($configuration['assume_loaded_is_success'] ?? false),
        ];
    }

    /** @return array{type:string,parameters:array<string,mixed>} */
    protected function trustedInitializationForParsedTag(array $parsed): array
    {
        if ((array) ($parsed['inlineCode'] ?? []) !== []) {
            throw new RuntimeException('Inline JavaScript is not a trusted recipe for this provider.');
        }

        return ['type' => 'NONE', 'parameters' => []];
    }

    /** @return array<int, string> */
    protected function allowedInitializationTypes(): array
    {
        return ['NONE'];
    }

    private function normalizeDirectRecipe(array $recipe, DemandPlacement $placement): array
    {
        $encoded = json_encode($recipe, JSON_UNESCAPED_SLASHES) ?: '';
        if (preg_match('/(?:env|file):|secret|password|credential|authorization|api[_-]?key|access[_-]?token|private[_-]?key/i', $encoded)) {
            throw new RuntimeException('Direct Demand recipe contains private or credential material.');
        }
        if (strtoupper((string) ($recipe['executionMode'] ?? 'STRUCTURED')) !== 'STRUCTURED') {
            throw new RuntimeException('Only reviewed structured recipes may be stored in direct_recipe.');
        }
        $scripts = [];
        foreach ((array) ($recipe['scripts'] ?? []) as $script) {
            $script = (array) $script;
            $url = trim((string) ($script['url'] ?? ''));
            $this->assertAllowedScriptUrl($url);
            $scripts[] = [
                'url' => $url,
                'async' => (bool) ($script['async'] ?? true),
                'defer' => (bool) ($script['defer'] ?? false),
                'dedupeKey' => preg_replace('/[^A-Za-z0-9_.:-]/', '-', (string) ($script['dedupeKey'] ?? hash('sha256', $url))),
                'attributes' => $this->publicAttributes((array) ($script['attributes'] ?? [])),
            ];
        }
        if ($scripts === []) throw new RuntimeException('Structured Direct Demand recipe has no approved script.');
        $initialization = (array) ($recipe['initialization'] ?? ['type' => 'NONE', 'parameters' => []]);
        $type = strtoupper((string) ($initialization['type'] ?? 'NONE'));
        if (! in_array($type, $this->allowedInitializationTypes(), true)) {
            throw new RuntimeException('The configured initialization action is not trusted by this provider connector.');
        }
        $container = (array) ($recipe['container'] ?? []);
        $containerId = preg_replace('/[^A-Za-z0-9_:-]/', '-', (string) ($container['id'] ?? 'hm-direct-'.$placement->id));
        $containerClass = preg_replace('/[^A-Za-z0-9_\- ]/', '-', (string) ($container['class'] ?? 'hm-direct-demand-container'));
        $attrs = $this->publicAttributes((array) ($container['attributes'] ?? []));
        $render = (array) ($recipe['render'] ?? []);
        $timeout = max(500, min(10000, (int) ($render['timeoutMs'] ?? config('demand.direct_render_timeout_ms', 2500))));
        $format = strtoupper((string) ($recipe['format'] ?? $placement->placement->type->value));

        return [
            'recipeVersion' => 1,
            'executionMode' => 'STRUCTURED',
            'format' => $format,
            'scripts' => $scripts,
            'container' => [
                'element' => strtolower((string) ($container['element'] ?? 'div')),
                'id' => $containerId,
                'class' => $containerClass,
                'attributes' => $attrs,
            ],
            'publicPlacementId' => (string) ($recipe['publicPlacementId'] ?? $containerId),
            'initialization' => [
                'type' => $type,
                'parameters' => collect((array) ($initialization['parameters'] ?? []))
                    ->filter(fn ($value, $key) => is_scalar($value) && ! preg_match('/secret|token|password|credential|authorization|api[_-]?key|private/i', (string) $key))
                    ->all(),
            ],
            'render' => [
                'timeoutMs' => $timeout,
                'successSelector' => isset($render['successSelector']) ? (string) $render['successSelector'] : null,
                'assumeLoadedIsSuccess' => (bool) ($render['assumeLoadedIsSuccess'] ?? false),
                'allowedFormats' => (array) ($render['allowedFormats'] ?? [$format]),
                'allowedSizes' => (array) ($render['allowedSizes'] ?? []),
            ],
            'isolation' => null,
            'scriptUrl' => $scripts[0]['url'],
            'containerId' => $containerId,
            'containerClass' => $containerClass,
            'attributes' => $attrs,
            'renderTimeoutMs' => $timeout,
            'successSelector' => isset($render['successSelector']) ? (string) $render['successSelector'] : null,
            'assumeLoadedIsSuccess' => (bool) ($render['assumeLoadedIsSuccess'] ?? false),
        ];
    }

    private function isolatedThirdPartyRecipe(string $html, array $configuration, DemandPlacement $placement): array
    {
        if (strlen($html) > 60_000 || preg_match('/(?:env|file):|secret|password|credential|authorization|api[_-]?key|access[_-]?token|private[_-]?key/i', $html)) {
            throw new RuntimeException('Custom third-party tag contains private or credential material.');
        }
        $this->assertSafeThirdPartyHtml($html);
        $origins = collect((array) ($configuration['isolation_allowed_origins'] ?? []))
            ->map(fn ($origin) => strtolower(rtrim((string) $origin, '/')))
            ->filter(function (string $origin): bool {
                if (! filter_var($origin, FILTER_VALIDATE_URL) || strtolower((string) parse_url($origin, PHP_URL_SCHEME)) !== 'https') return false;
                $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
                return $host !== 'app.horusmedia.net' && ! str_ends_with($host, '.app.horusmedia.net');
            })->unique()->values();
        if ($origins->isEmpty()) {
            throw new RuntimeException('Custom isolated tags require explicit provider CSP origins.');
        }
        $originList = $origins->implode(' ');
        $csp = "default-src 'none'; script-src 'unsafe-inline' {$originList}; connect-src {$originList}; img-src https: data:; style-src 'unsafe-inline'; frame-src {$originList};";
        $format = strtoupper((string) ($configuration['format'] ?? $placement->placement->type->value));
        $timeout = max(500, min(10000, (int) ($configuration['render_timeout_ms'] ?? config('demand.direct_render_timeout_ms', 2500))));

        return [
            'recipeVersion' => 1,
            'executionMode' => 'ISOLATED_IFRAME',
            'format' => $format,
            'scripts' => [],
            'container' => ['element' => 'div', 'id' => 'hm-isolated-'.$placement->id, 'class' => 'hm-direct-demand-isolated', 'attributes' => []],
            'publicPlacementId' => (string) ($placement->remote_placement_id ?? $placement->placement_code ?? $placement->id),
            'initialization' => ['type' => 'NONE', 'parameters' => []],
            'render' => ['timeoutMs' => $timeout, 'successSelector' => null, 'assumeLoadedIsSuccess' => true, 'allowedFormats' => [$format], 'allowedSizes' => []],
            'isolation' => ['html' => $html, 'csp' => $csp, 'sandbox' => ['allow-scripts']],
            'scriptUrl' => '',
            'containerId' => 'hm-isolated-'.$placement->id,
            'containerClass' => 'hm-direct-demand-isolated',
            'attributes' => [],
            'renderTimeoutMs' => $timeout,
            'successSelector' => null,
            'assumeLoadedIsSuccess' => true,
        ];
    }

    private function tagReview(array $parsed, ?array $recipe, array $warnings): array
    {
        return [
            'safe' => $recipe !== null && $warnings === [],
            'recipe' => $recipe,
            'detectedScripts' => $parsed['detectedScripts'] ?? [],
            'detectedContainers' => $parsed['detectedContainers'] ?? [],
            'detectedPublicIdentifiers' => $parsed['detectedPublicIdentifiers'] ?? [],
            'detectedAttributes' => data_get($parsed, 'detectedContainers.0.attributes', []),
            'unsupportedInlineCode' => $parsed['unsupportedInlineCode'] ?? [],
            'securityWarnings' => $warnings,
        ];
    }

    public function generateGamCreative(DemandPlacement $placement): array
    {
        $widget = $this->widget($placement);
        $tag = $this->generateDirectTag($placement);
        $container = htmlspecialchars($tag['containerId'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $class = htmlspecialchars($tag['containerClass'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $script = htmlspecialchars($tag['scriptUrl'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $snippet = $widget?->gam_creative_template ?: sprintf(
            '<div id="%s" class="%s"></div><script async src="%s"></script>',
            $container,
            $class,
            $script,
        );
        $this->assertSafeThirdPartyHtml($snippet);

        return [
            'name' => $this->code().' - '.$placement->placement->name,
            'creativeType' => 'THIRD_PARTY',
            'size' => $this->creativeSize($placement),
            'snippet' => $snippet,
            'safeFrameCompatible' => true,
        ];
    }

    public function getAdsTxtRecords(?DemandSite $site = null): array
    {
        $records = (array) data_get($this->selectedAccount->configuration, 'ads_txt_records', []);

        return collect($records)
            ->map(fn ($record) => $this->normalizeAdsTxtRecord($record))
            ->filter()
            ->values()
            ->all();
    }

    public function runReport(CarbonInterface $from, CarbonInterface $to, array $options = []): DemandResult
    {
        $configuration = $this->selectedAccount->configuration ?? [];
        if (empty($configuration['report_path'])) {
            return DemandResult::failure(
                'CONFIGURATION',
                'REPORT_API_NOT_CONFIGURED',
                'Report API access is not configured. Use the CSV fallback for this account.'
            );
        }

        $query = [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ] + (array) ($options['filters'] ?? []);

        if (($options['dry_run'] ?? false) === true) {
            return DemandResult::dryRun(['method' => 'GET', 'path' => $configuration['report_path'], 'query' => $query]);
        }

        return $this->request('GET', (string) $configuration['report_path'], $query, true);
    }

    public function pausePlacement(DemandPlacement $placement, array $options = []): DemandResult
    {
        return $this->placementAction($placement, 'pause_placement_path', 'PAUSED', $options);
    }

    public function activatePlacement(DemandPlacement $placement, array $options = []): DemandResult
    {
        return $this->placementAction($placement, 'activate_placement_path', 'ACTIVE', $options);
    }

    protected function requiredAccountIdentifier(): array
    {
        if ($this->selectedAccount->account_identifier || data_get($this->selectedAccount->configuration, 'publisher_id')) {
            return [];
        }

        return [$this->code().' requires an account identifier or publisher_id supplied by the approved provider account.'];
    }

    protected function mergedConfiguration(DemandPlacement $placement, ?DemandWidget $widget): array
    {
        return array_replace_recursive(
            (array) ($this->selectedAccount->configuration ?? []),
            (array) ($placement->demandSite->configuration ?? []),
            (array) ($placement->configuration ?? []),
            (array) ($widget?->configuration ?? []),
        );
    }

    protected function widget(DemandPlacement $placement): ?DemandWidget
    {
        $placement->loadMissing(['widgets', 'placement.sizes', 'demandSite.site']);

        return $placement->widgets
            ->filter(fn (DemandWidget $widget) => $widget->is_enabled
                && $widget->approval_status === DemandApprovalStatus::Approved)
            ->sortBy('created_at')
            ->first();
    }

    protected function assertAllowedScriptUrl(string $url): void
    {
        if (! filter_var($url, FILTER_VALIDATE_URL) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new RuntimeException('Demand script URLs must be valid HTTPS URLs.');
        }

        $origin = strtolower((string) parse_url($url, PHP_URL_SCHEME)).'://'.strtolower((string) parse_url($url, PHP_URL_HOST));
        $allowed = collect($this->selectedAccount->network->script_origins ?? [])
            ->merge((array) config('demand.allowed_script_origins.'.$this->code(), []))
            ->merge((array) data_get($this->selectedAccount->configuration, 'allowed_script_origins', []))
            ->map(fn ($value) => strtolower(rtrim((string) $value, '/')))
            ->unique();

        if ($allowed->isEmpty() || ! $allowed->contains($origin)) {
            throw new RuntimeException("Demand script origin [{$origin}] is not allowlisted for ".$this->code().'.');
        }
    }

    protected function request(
        string $method,
        string $path,
        array $payload = [],
        bool $query = false,
        array $headers = [],
    ): DemandResult
    {
        try {
            $configuration = $this->selectedAccount->configuration ?? [];
            $request = Http::acceptJson()
                ->timeout((int) config('demand.connection_timeout_seconds', 10));
            if ($headers !== []) {
                $request = $request->withHeaders($headers);
            }

            $token = $this->secrets->resolve($this->selectedAccount, (string) ($configuration['api_token_credential_key'] ?? 'api_token'));
            if ($token) {
                $request = $request->withToken($token);
            }

            $url = $this->apiUrl($path);
            $response = $query
                ? $request->send(strtoupper($method), $url, ['query' => $payload])
                : $request->send(strtoupper($method), $url, ['json' => $payload]);

            $response->throw();

            return DemandResult::success((array) ($response->json() ?? ['status' => $response->status()]));
        } catch (RequestException $exception) {
            $status = $exception->response?->status();

            return DemandResult::failure(
                'HTTP',
                $status ? (string) $status : 'REQUEST_FAILED',
                $exception->getMessage(),
                $status === 429 || ($status !== null && $status >= 500),
            );
        } catch (Throwable $exception) {
            return DemandResult::failure('TRANSPORT', 'REQUEST_FAILED', $exception->getMessage(), false);
        }
    }

    protected function apiUrl(string $path): string
    {
        $base = rtrim((string) data_get($this->selectedAccount->configuration, 'api_base_url'), '/');
        if (! filter_var($base, FILTER_VALIDATE_URL) || strtolower((string) parse_url($base, PHP_URL_SCHEME)) !== 'https') {
            throw new RuntimeException('API integration requires an approved HTTPS api_base_url.');
        }

        return $base.'/'.ltrim($path, '/');
    }

    private function placementAction(DemandPlacement $placement, string $configurationKey, string $fallbackStatus, array $options): DemandResult
    {
        $configuration = $this->selectedAccount->configuration ?? [];
        if (! $placement->remote_placement_id || empty($configuration[$configurationKey])) {
            return DemandResult::success([
                'status' => $fallbackStatus,
                'source' => 'LOCAL_CONTROL',
                'remote_placement_id' => $placement->remote_placement_id,
            ]);
        }

        $path = str_replace(
            '{placement_id}',
            rawurlencode($placement->remote_placement_id),
            (string) $configuration[$configurationKey],
        );

        if (($options['dry_run'] ?? false) === true) {
            return DemandResult::dryRun(['method' => 'POST', 'path' => $path]);
        }

        return $this->request(
            'POST',
            $path,
            [],
            false,
            $this->idempotencyHeaders($options, strtolower($fallbackStatus).':'.$placement->id),
        );
    }



    private function assertSafeThirdPartyHtml(string $html): void
    {
        $unsafe = [
            '/document\s*\.\s*cookie/i',
            '/(?:local|session)Storage/i',
            '/javascript\s*:/i',
            '/\b(?:eval|Function)\s*\(/i',
            '/(?:window\s*\.\s*)?top\s*\.\s*(?:location|document)/i',
            '/<\s*(?:object|embed|applet|base|meta)\b/i',
            '/(?:env|file):[A-Za-z0-9_\/.:-]+/i',
        ];
        foreach ($unsafe as $pattern) {
            if (preg_match($pattern, $html)) {
                throw new RuntimeException('The configured third-party creative contains unsafe or private content.');
            }
        }

        if (preg_match_all('/<script\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1/is', $html, $matches)) {
            foreach ($matches[2] as $url) {
                $this->assertAllowedScriptUrl(html_entity_decode((string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }
        if (preg_match('/(?:src|href)\s*=\s*(["\'])http:\/\//i', $html)) {
            throw new RuntimeException('Third-party creative assets must use HTTPS.');
        }
    }

    private function idempotencyHeaders(array $options, string $fallbackScope): array
    {
        $key = trim((string) ($options['idempotency_key'] ?? ''));
        if ($key === '') {
            $key = hash('sha256', $this->selectedAccount->id.'|'.$fallbackScope);
        }

        return ['Idempotency-Key' => $key];
    }

    private function publicAttributes(array $attributes): array
    {
        return collect($attributes)
            ->filter(fn ($value, $key) => preg_match('/^[A-Za-z_:][-A-Za-z0-9_:.]*$/', (string) $key))
            ->reject(fn ($value, $key) => preg_match('/^(?:on|srcdoc$)|secret|token|password|credential|key/i', (string) $key))
            ->map(fn ($value) => is_scalar($value) ? (string) $value : null)
            ->reject(fn ($value) => preg_match('/javascript\s*:/i', (string) $value))
            ->filter(fn ($value) => $value !== null)
            ->all();
    }

    private function creativeSize(DemandPlacement $placement): array
    {
        $size = $placement->placement->sizes
            ->where('is_active', true)
            ->first(fn ($candidate) => $candidate->size_type === 'FIXED' && $candidate->width && $candidate->height);

        return $size
            ? ['width' => (int) $size->width, 'height' => (int) $size->height]
            : ['width' => 1, 'height' => 1];
    }

    private function normalizeAdsTxtRecord(mixed $record): ?array
    {
        if (is_string($record)) {
            $parts = array_map('trim', explode(',', $record));
            if (count($parts) < 3) {
                return null;
            }
            $record = [
                'domain' => $parts[0],
                'publisher_account_id' => $parts[1],
                'relationship' => strtoupper($parts[2]),
                'certification_authority_id' => $parts[3] ?? null,
            ];
        }

        if (! is_array($record)) {
            return null;
        }

        $domain = strtolower(trim((string) ($record['domain'] ?? '')));
        $publisher = trim((string) ($record['publisher_account_id'] ?? ''));
        $relationship = strtoupper(trim((string) ($record['relationship'] ?? '')));

        if (! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain) || $publisher === '' || ! in_array($relationship, ['DIRECT', 'RESELLER'], true)) {
            return null;
        }

        $authority = trim((string) ($record['certification_authority_id'] ?? ''));
        $raw = implode(', ', array_filter([$domain, $publisher, $relationship, $authority]));

        return [
            'domain' => $domain,
            'publisher_account_id' => $publisher,
            'relationship' => $relationship,
            'certification_authority_id' => $authority ?: null,
            'raw_record' => $raw,
            'record_hash' => hash('sha256', strtolower($raw)),
        ];
    }
}
