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

    public function generateDirectTag(DemandPlacement $placement): array
    {
        $widget = $this->widget($placement);
        $configuration = $this->mergedConfiguration($placement, $widget);
        $scriptUrl = trim((string) ($configuration['script_url'] ?? ''));

        if ($scriptUrl === '') {
            throw new RuntimeException($this->code().' direct delivery requires an approved script_url.');
        }

        $this->assertAllowedScriptUrl($scriptUrl);

        $containerId = (string) (
            $configuration['container_id']
            ?? $widget?->widget_code
            ?? $placement->placement_code
            ?? 'hm-native-'.$placement->id
        );

        return [
            'scriptUrl' => $scriptUrl,
            'containerId' => preg_replace('/[^A-Za-z0-9_:-]/', '-', $containerId),
            'containerClass' => (string) ($configuration['container_class'] ?? 'hm-native-container'),
            'attributes' => $this->publicAttributes((array) ($configuration['attributes'] ?? [])),
            'renderTimeoutMs' => max(500, min(10000, (int) ($configuration['render_timeout_ms'] ?? config('demand.direct_render_timeout_ms', 2500)))),
            'successSelector' => isset($configuration['success_selector']) ? (string) $configuration['success_selector'] : null,
            'assumeLoadedIsSuccess' => (bool) ($configuration['assume_loaded_is_success'] ?? false),
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
