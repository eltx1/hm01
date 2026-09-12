<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ConfigEnvironment;
use App\Enums\DemandAccountScope;
use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Enums\DemandNetworkCode;
use App\Enums\PlacementStatus;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Http\Controllers\Controller;
use App\Models\DemandAccount;
use App\Models\DemandNetwork;
use App\Models\DemandPlacement;
use App\Models\DemandWidget;
use App\Models\Placement;
use App\Models\Publisher;
use App\Models\Site;
use App\Services\Audit\AuditRecorder;
use App\Services\Demand\DemandAccountService;
use App\Services\Demand\DemandConnectorManager;
use App\Services\Demand\DirectTagRecipeParser;
use App\Services\Inventory\SiteConfigurationBuilder;
use App\Services\Inventory\SiteConfigPublisher;
use App\Services\Operations\PlatformControlService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

final class DirectDemandQuickMonetizeController extends Controller
{
    public function create(Request $request, PlatformControlService $controls): View
    {
        $network = $this->network();
        $sites = Site::withoutGlobalScopes()
            ->with([
                'publisher',
                'placements' => fn ($query) => $query
                    ->withoutGlobalScopes()
                    ->whereNull('placements.deleted_at')
                    ->where('status', PlacementStatus::Active->value)
                    ->with('sizes')
                    ->orderBy('sort_order')
                    ->orderBy('name'),
            ])
            ->whereNull('sites.deleted_at')
            ->where('status', SiteStatus::Active->value)
            ->whereNotNull('publisher_id')
            ->orderBy('display_name')
            ->get();

        return view('admin.demand.quick', [
            'sites' => $sites,
            'network' => $network,
            'blockingReasons' => $this->readinessProblems($controls, $network),
            'selectedSiteId' => (string) $request->query('site', ''),
        ]);
    }

    public function store(
        Request $request,
        DemandAccountService $accounts,
        DemandConnectorManager $connectors,
        DirectTagRecipeParser $parser,
        SiteConfigurationBuilder $siteConfigurationBuilder,
        SiteConfigPublisher $configPublisher,
        PlatformControlService $controls,
        AuditRecorder $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'site_id' => ['required', 'ulid', 'exists:sites,id'],
            'placement_id' => ['required', 'ulid', 'exists:placements,id'],
            'tag' => ['required', 'string', 'max:60000'],
        ]);

        $network = $this->network();
        $problems = $this->readinessProblems($controls, $network);
        if ($problems !== []) {
            throw ValidationException::withMessages(['quick' => implode(' ', $problems)]);
        }
        if (! $network) {
            throw ValidationException::withMessages(['quick' => 'Custom Third-Party Tag connector is unavailable.']);
        }

        $site = Site::withoutGlobalScopes()
            ->with(['publisher', 'siteConfig'])
            ->whereNull('deleted_at')
            ->findOrFail($data['site_id']);
        $placement = Placement::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->findOrFail($data['placement_id']);

        if ($site->status !== SiteStatus::Active) {
            throw ValidationException::withMessages(['site_id' => 'Quick Monetize is available only for active websites.']);
        }
        if ($site->serving_mode === ServingMode::Paused || $site->siteConfig?->immediate_pause || ($site->siteConfig && $site->siteConfig->status !== 'ACTIVE')) {
            throw ValidationException::withMessages(['site_id' => 'This website is operationally paused. Resume it before publishing a new ad tag.']);
        }
        if (! $site->publisher) {
            throw ValidationException::withMessages(['site_id' => 'The selected website is not attached to a Publisher.']);
        }
        if ($placement->site_id !== $site->id) {
            throw ValidationException::withMessages(['placement_id' => 'The selected placement does not belong to this website.']);
        }
        if ($placement->status !== PlacementStatus::Active) {
            throw ValidationException::withMessages(['placement_id' => 'Quick Monetize requires an active placement.']);
        }

        $siteProblems = $this->siteReadinessProblems($controls, $site, $placement);
        if ($siteProblems !== []) {
            throw ValidationException::withMessages(['quick' => implode(' ', $siteProblems)]);
        }

        $tag = trim((string) $data['tag']);
        $review = $parser->parse($tag);
        $warnings = array_values((array) ($review['securityWarnings'] ?? []));
        if ((bool) ($review['containsSensitiveMaterial'] ?? false) || $warnings !== []) {
            throw ValidationException::withMessages([
                'tag' => $warnings !== []
                    ? implode(' ', $warnings)
                    : 'The supplied public tag contains material that cannot be published safely.',
            ]);
        }

        $origins = $this->scriptOrigins((array) ($review['detectedScripts'] ?? []));
        if ($origins === []) {
            throw ValidationException::withMessages(['tag' => 'Quick Monetize requires at least one HTTPS provider script.']);
        }
        if (count((array) ($review['detectedContainers'] ?? [])) !== 1) {
            throw ValidationException::withMessages(['tag' => 'Quick Monetize requires exactly one render container. Use Advanced setup for complex multi-container tags.']);
        }

        $account = DB::transaction(function () use (
            $request,
            $accounts,
            $connectors,
            $siteConfigurationBuilder,
            $configPublisher,
            $audit,
            $network,
            $site,
            $placement,
            $tag,
            $origins,
        ): DemandAccount {
            $actor = $request->user();

            // Serialize first-time Quick Monetize account creation per Publisher.
            // Production MySQL row locking prevents two simultaneous submissions
            // from creating duplicate managed accounts before either can commit.
            $publisher = Publisher::withoutGlobalScopes()
                ->whereKey($site->publisher_id)
                ->lockForUpdate()
                ->firstOrFail();

            $account = DemandAccount::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->where('demand_network_id', $network->id)
                ->where('publisher_id', $publisher->id)
                ->where('scope', DemandAccountScope::Publisher->value)
                ->get()
                ->first(fn (DemandAccount $candidate) => (bool) data_get($candidate->configuration, 'quick_monetize_managed', false));

            if (! $account) {
                $account = $accounts->create([
                    'demand_network_id' => $network->id,
                    'publisher_id' => $publisher->id,
                    'name' => 'Quick Manual Tags · '.$publisher->display_name,
                    'scope' => DemandAccountScope::Publisher,
                    'integration_mode' => DemandIntegrationMode::ManualTag,
                    'approval_status' => DemandApprovalStatus::Approved,
                    'is_enabled' => true,
                    'is_default' => false,
                    'revenue_share_percent' => $this->publisherRevenueShare($site),
                    'fallback_priority' => 100,
                    'account_identifier' => null,
                    'configuration' => [
                        'quick_monetize_managed' => true,
                        'allowed_script_origins' => $origins,
                        'render_timeout_ms' => 2500,
                    ],
                ], $actor);
            } else {
                $mergedOrigins = collect((array) data_get($account->configuration, 'allowed_script_origins', []))
                    ->merge($origins)
                    ->unique()
                    ->values()
                    ->all();
                $configuration = (array) ($account->configuration ?? []);
                $configuration['quick_monetize_managed'] = true;
                $configuration['allowed_script_origins'] = $mergedOrigins;
                $configuration['render_timeout_ms'] ??= 2500;

                $account = $accounts->update($account, [
                    'integration_mode' => DemandIntegrationMode::ManualTag,
                    'approval_status' => DemandApprovalStatus::Approved,
                    'is_enabled' => true,
                    'configuration' => $configuration,
                ], $actor);
            }

            $demandSite = $accounts->assignSite($account, $site, [
                'approval_status' => DemandApprovalStatus::Approved->value,
                'is_enabled' => true,
                'is_default' => true,
                'integration_mode' => DemandIntegrationMode::ManualTag->value,
                'fallback_priority' => 100,
                'configuration' => ['quick_monetize_managed' => true],
            ], $actor);

            $demandPlacement = $accounts->assignPlacement($demandSite, $placement, [
                'approval_status' => DemandApprovalStatus::Approved->value,
                'is_enabled' => true,
                'integration_mode' => DemandIntegrationMode::ManualTag->value,
                'fallback_priority' => 100,
                'placement_code' => $placement->code,
                'configuration' => ['quick_monetize_managed' => true],
            ], $actor);

            // A widget name is presentation, not identity. If an operator renamed
            // the Quick widget in Advanced setup, update that same Quick-managed
            // row instead of creating a second approved renderer. ULIDs give a
            // deterministic latest-row fallback for data that already contains a
            // duplicate from an older Quick implementation.
            $existingQuickWidget = DemandWidget::withoutGlobalScopes()
                ->where('demand_placement_id', $demandPlacement->id)
                ->get()
                ->filter(fn (DemandWidget $widget) => (bool) data_get($widget->configuration, 'quick_monetize_managed', false))
                ->sortByDesc('id')
                ->first();

            $accounts->upsertWidget($demandPlacement, [
                'name' => $existingQuickWidget?->name ?? 'Quick Manual · '.$placement->code,
                'widget_code' => 'quick-'.$placement->code,
                'integration_mode' => DemandIntegrationMode::ManualTag->value,
                'direct_tag_template' => $tag,
                'approval_status' => DemandApprovalStatus::Approved->value,
                'is_enabled' => true,
                'configuration' => [
                    'quick_monetize_managed' => true,
                    'isolation_allowed_origins' => $origins,
                    'render_timeout_ms' => 2500,
                ],
            ], $actor);

            $beforeDirectDemand = (bool) $site->native_demand_enabled;
            if (! $beforeDirectDemand) {
                $site->update(['native_demand_enabled' => true]);
                $audit->record(
                    'demand.site.direct_demand_enabled_changed',
                    $site->organization_id,
                    $actor,
                    $site,
                    ['native_demand_enabled' => false],
                    ['native_demand_enabled' => true],
                );
            }

            $demandPlacement = DemandPlacement::withoutGlobalScopes()
                ->with(['demandSite.account.network', 'placement.sizes', 'widgets'])
                ->findOrFail($demandPlacement->id);
            $connector = $connectors->for($account->refresh()->load('network'));
            $connectorReview = $connector->parseDirectTag($tag);
            if (! (bool) ($connectorReview['safe'] ?? false)) {
                $connectorWarnings = array_values((array) ($connectorReview['securityWarnings'] ?? []));
                throw ValidationException::withMessages([
                    'tag' => $connectorWarnings !== []
                        ? implode(' ', $connectorWarnings)
                        : 'The supplied third-party tag did not pass the connector security review.',
                ]);
            }

            try {
                $recipe = $connector->generateDirectTag($demandPlacement);
            } catch (ValidationException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw ValidationException::withMessages([
                    'tag' => $exception->getMessage() !== ''
                        ? $exception->getMessage()
                        : 'Quick Monetize could not create a trusted runtime recipe. No changes were published.',
                ]);
            }

            $reviewMode = strtoupper((string) data_get($connectorReview, 'recipe.executionMode', ''));
            $actualMode = strtoupper((string) ($recipe['executionMode'] ?? ''));
            if (! in_array($actualMode, ['STRUCTURED', 'ISOLATED_IFRAME'], true)
                || $actualMode !== $reviewMode
                || (data_get($connectorReview, 'recipe.provider') === 'GOOGLE_GPT' && $actualMode !== 'STRUCTURED')) {
                throw ValidationException::withMessages([
                    'tag' => 'Quick Monetize could not create the reviewed trusted runtime recipe. No changes were published.',
                ]);
            }

            // Validate the complete renderer decision, not only the Direct Demand
            // candidate. If GAM or standalone Prebid already owns this physical
            // placement, the whole transaction rolls back instead of disabling or
            // double-rendering the existing ad surface.
            $finalConfig = $siteConfigurationBuilder->build(
                $site->fresh(),
                ConfigEnvironment::Production,
                0,
            );
            $finalPlacement = collect((array) ($finalConfig['placements'] ?? []))
                ->first(fn (array $candidate) => ($candidate['code'] ?? null) === $placement->code);
            $directCandidates = (array) ($finalConfig['directDemand']['placements'][$placement->code]['candidates'] ?? []);

            if ($directCandidates === [] || ! is_array($finalPlacement)) {
                throw ValidationException::withMessages([
                    'tag' => 'The tag passed parsing but could not produce a deliverable Direct Demand candidate. No changes were published.',
                ]);
            }
            if ((bool) ($finalPlacement['rendererConflict'] ?? false)
                || ($finalPlacement['renderer'] ?? null) !== 'DIRECT_JS'
                || ! (bool) ($finalPlacement['enabled'] ?? false)) {
                throw ValidationException::withMessages([
                    'placement_id' => 'This placement is already owned by another renderer or is not eligible for Direct Demand. Quick Monetize will not replace or double-render it. Choose another placement or use Advanced setup.',
                ]);
            }

            $configPublisher->publishActiveProduction($site->fresh(), $actor);

            return $account->refresh();
        });

        return redirect()
            ->route('admin.demand.quick.create', ['site' => $site->id])
            ->with('status', "{$placement->name} is monetized on {$site->primary_domain}. Horus created the account wiring, reviewed the tag, mapped the placement, and queued production automatically.")
            ->with('quick_account_id', $account->id);
    }

    private function network(): ?DemandNetwork
    {
        return DemandNetwork::query()
            ->where('code', DemandNetworkCode::CustomThirdPartyTag->value)
            ->first();
    }

    /** @return array<int, string> */
    private function readinessProblems(PlatformControlService $controls, ?DemandNetwork $network): array
    {
        $problems = [];
        if ($controls->disabled('PLATFORM', null, 'DIRECT_JS')) {
            $problems[] = 'Direct Demand master is paused.';
        }
        if (! $network) {
            $problems[] = 'Custom Third-Party Tag connector is missing.';

            return $problems;
        }
        if (! $network->is_enabled) {
            $problems[] = 'Custom Third-Party Tag connector is disabled.';
        }
        if (! $network->supports_direct_js) {
            $problems[] = 'Custom Third-Party Tag direct delivery is disabled.';
        }
        foreach (['DIRECT_JS', 'AD_SERVING', 'NATIVE_DEMAND'] as $control) {
            if ($controls->disabled('DEMAND_NETWORK', $network->id, $control)) {
                $problems[] = "Connector runtime {$control} is paused.";
            }
        }

        return array_values(array_unique($problems));
    }

    /** @return array<int, string> */
    private function siteReadinessProblems(PlatformControlService $controls, Site $site, Placement $placement): array
    {
        $problems = [];
        if ($controls->disabledForSite('AD_SERVING', $site->id, $site->gam_connection_id)) {
            $problems[] = 'Ad serving is paused for this website.';
        }
        if ($controls->disabledForSite('DIRECT_JS', $site->id)) {
            $problems[] = 'Direct Demand is paused for this website.';
        }
        if ($controls->disabledForSite('NATIVE_DEMAND', $site->id)) {
            $problems[] = 'The website Direct Demand compatibility control is paused.';
        }
        if ($controls->placementEngineDisabled($placement->id, 'DIRECT_JS')) {
            $problems[] = 'Direct Demand is paused for this placement.';
        }
        if ($controls->placementEngineDisabled($placement->id, 'AD_SERVING')) {
            $problems[] = 'Ad serving is paused for this placement.';
        }

        return array_values(array_unique($problems));
    }

    /** @param array<int, array<string, mixed>> $scripts
     *  @return array<int, string>
     */
    private function scriptOrigins(array $scripts): array
    {
        $origins = [];
        foreach ($scripts as $script) {
            $url = trim((string) ($script['url'] ?? ''));
            if (str_starts_with($url, '//')) {
                $url = 'https:'.$url;
            }
            if (! filter_var($url, FILTER_VALIDATE_URL)
                || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
                throw ValidationException::withMessages(['tag' => 'Provider script URLs must use HTTPS.']);
            }
            $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
            if (! $this->publicHost($host)) {
                throw ValidationException::withMessages(['tag' => "Provider script host [{$host}] is private, reserved, or otherwise unsafe for publisher delivery."]);
            }
            if ($host === 'app.horusmedia.net' || str_ends_with($host, '.app.horusmedia.net')) {
                throw ValidationException::withMessages(['tag' => 'Provider tags may not authorize the Horus control-plane origin.']);
            }
            $port = parse_url($url, PHP_URL_PORT);
            $origins[] = 'https://'.$host.($port && (int) $port !== 443 ? ':'.(int) $port : '');
        }

        return array_values(array_unique($origins));
    }

    private function publicHost(string $host): bool
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
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return str_contains($host, '.') && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    private function publisherRevenueShare(Site $site): string
    {
        $share = $site->revenue_share_percent;
        if ($share !== null) {
            return number_format((float) $share, 4, '.', '');
        }

        return number_format((float) config('commercial.default_publisher_revenue_share_percent', 70), 4, '.', '');
    }
}
