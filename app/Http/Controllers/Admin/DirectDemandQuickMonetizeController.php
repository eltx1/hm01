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
use App\Models\DemandSite;
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
use App\Services\Security\PublicProviderOriginValidator;
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

        $scriptOrigins = $this->scriptOrigins((array) ($review['detectedScripts'] ?? []));
        if ($scriptOrigins === []) {
            throw ValidationException::withMessages(['tag' => 'Quick Monetize requires at least one HTTPS provider script.']);
        }
        $resourceOrigins = $this->resourceOrigins($tag);
        $isolationOrigins = collect($scriptOrigins)
            ->merge($resourceOrigins['all'])
            ->unique()
            ->values()
            ->all();
        if (count($isolationOrigins) > 20) {
            throw ValidationException::withMessages([
                'tag' => 'Quick Monetize supports at most 20 distinct provider resource origins per tag. Use Advanced setup for more complex provider tags.',
            ]);
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
            $scriptOrigins,
            $resourceOrigins,
            $isolationOrigins,
        ): DemandAccount {
            $actor = $request->user();
            $siteRevenueShare = $this->publisherRevenueShare($site);

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
                    'revenue_share_percent' => $siteRevenueShare,
                    'fallback_priority' => 100,
                    'account_identifier' => null,
                    'configuration' => [
                        'quick_monetize_managed' => true,
                        'allowed_script_origins' => $scriptOrigins,
                        'render_timeout_ms' => 2500,
                    ],
                ], $actor);
            } else {
                $mergedOrigins = collect((array) data_get($account->configuration, 'allowed_script_origins', []))
                    ->merge($scriptOrigins)
                    ->unique()
                    ->values()
                    ->all();
                $configuration = (array) ($account->configuration ?? []);
                $configuration['quick_monetize_managed'] = true;
                $configuration['allowed_script_origins'] = $mergedOrigins;
                $configuration['render_timeout_ms'] ??= 2500;

                // Quick Monetize owns this managed account's renderer recipe.
                // An Advanced account-level recipe must never shadow the public
                // tag the operator is activating now.
                unset($configuration['direct_recipe']);

                $account = $accounts->update($account, [
                    'integration_mode' => DemandIntegrationMode::ManualTag,
                    'approval_status' => DemandApprovalStatus::Approved,
                    'is_enabled' => true,
                    'configuration' => $configuration,
                ], $actor);
            }

            // Quick owns the activation fields below, but a replacement tag must
            // not erase Advanced state that is unrelated to Quick Monetize. In
            // particular, retain remote identifiers and merge configuration.
            $existingDemandSite = DemandSite::withoutGlobalScopes()
                ->where('demand_account_id', $account->id)
                ->where('site_id', $site->id)
                ->first();
            $siteMappingConfiguration = (array) ($existingDemandSite?->configuration ?? []);
            $siteMappingConfiguration['quick_monetize_managed'] = true;

            // Revenue share is site/commercial state, not a field the operator
            // should re-enter in Quick Monetize. Keep the account default for the
            // first site and always persist the effective site-specific override
            // on the mapping so one Publisher account can safely serve sites with
            // different commercial terms.
            $demandSite = $accounts->assignSite($account, $site, [
                'approval_status' => DemandApprovalStatus::Approved->value,
                'is_enabled' => true,
                'is_default' => true,
                'integration_mode' => DemandIntegrationMode::ManualTag->value,
                'revenue_share_percent' => $siteRevenueShare,
                'fallback_priority' => 100,
                'remote_site_id' => $existingDemandSite?->remote_site_id,
                'configuration' => $siteMappingConfiguration,
            ], $actor);

            $existingDemandPlacement = DemandPlacement::withoutGlobalScopes()
                ->where('demand_site_id', $demandSite->id)
                ->where('placement_id', $placement->id)
                ->first();
            $placementMappingConfiguration = (array) ($existingDemandPlacement?->configuration ?? []);
            $placementMappingConfiguration['quick_monetize_managed'] = true;

            $demandPlacement = $accounts->assignPlacement($demandSite, $placement, [
                'approval_status' => DemandApprovalStatus::Approved->value,
                'is_enabled' => true,
                'integration_mode' => DemandIntegrationMode::ManualTag->value,
                'fallback_priority' => 100,
                'remote_placement_id' => $existingDemandPlacement?->remote_placement_id,
                'placement_code' => $existingDemandPlacement?->placement_code ?? $placement->code,
                'configuration' => $placementMappingConfiguration,
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
            $widgetConfiguration = (array) ($existingQuickWidget?->configuration ?? []);
            $widgetConfiguration['quick_monetize_managed'] = true;
            $widgetConfiguration['isolation_allowed_origins'] = $isolationOrigins;
            $widgetConfiguration['isolation_script_origins'] = $scriptOrigins;
            $widgetConfiguration['isolation_frame_origins'] = $resourceOrigins['frame'];
            $widgetConfiguration['isolation_image_origins'] = $resourceOrigins['image'];
            $widgetConfiguration['isolation_style_origins'] = $resourceOrigins['style'];
            $widgetConfiguration['isolation_media_origins'] = $resourceOrigins['media'];
            $widgetConfiguration['isolation_font_origins'] = $resourceOrigins['font'];
            $widgetConfiguration['render_timeout_ms'] ??= 2500;

            $accounts->upsertWidget($demandPlacement, [
                'name' => $existingQuickWidget?->name ?? 'Quick Manual · '.$placement->code,
                'widget_code' => 'quick-'.$placement->code,
                'integration_mode' => DemandIntegrationMode::ManualTag->value,
                'direct_tag_template' => $tag,
                'approval_status' => DemandApprovalStatus::Approved->value,
                'is_enabled' => true,
                'configuration' => $widgetConfiguration,
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
        $validator = app(PublicProviderOriginValidator::class);
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

            $origin = $validator->canonicalOrigin($url);
            if ($origin === null) {
                $host = $validator->normalizeHost((string) parse_url($url, PHP_URL_HOST));
                throw ValidationException::withMessages([
                    'tag' => "Provider script host [{$host}] is private, reserved, unresolved, control-plane, or otherwise unsafe for publisher delivery.",
                ]);
            }
            $origins[] = $origin;
        }

        return array_values(array_unique($origins));
    }

    /**
     * @return array{all:array<int,string>,frame:array<int,string>,image:array<int,string>,style:array<int,string>,media:array<int,string>,font:array<int,string>}
     */
    private function resourceOrigins(string $tag): array
    {
        $validator = app(PublicProviderOriginValidator::class);
        $references = [];

        preg_match_all('/<\s*(img|iframe|source|video|audio|track|input|link)\b([^>]*)>/is', $tag, $elements, PREG_SET_ORDER);
        foreach ($elements as $element) {
            $name = strtolower((string) ($element[1] ?? ''));
            $attributes = $this->htmlAttributes((string) ($element[2] ?? ''));

            $add = function (string $value, array $types, bool $srcset = false) use (&$references): void {
                $value = trim($value);
                if ($value === '') {
                    return;
                }
                if ($srcset) {
                    foreach (preg_split('/\s*,\s*/', $value) ?: [] as $candidate) {
                        $candidate = trim((string) $candidate);
                        if ($candidate === '') {
                            continue;
                        }
                        $parts = preg_split('/\s+/', $candidate, 2);
                        $references[] = [(string) ($parts[0] ?? ''), $types];
                    }

                    return;
                }
                $references[] = [$value, $types];
            };

            if ($name === 'img') {
                $add((string) ($attributes['src'] ?? ''), ['image']);
                $add((string) ($attributes['srcset'] ?? ''), ['image'], true);
            } elseif ($name === 'iframe') {
                $add((string) ($attributes['src'] ?? ''), ['frame']);
            } elseif ($name === 'source') {
                // A source element can belong to picture, video, or audio. Grant
                // only passive image/media fetch capabilities, never scripts.
                $add((string) ($attributes['src'] ?? ''), ['image', 'media']);
                $add((string) ($attributes['srcset'] ?? ''), ['image', 'media'], true);
            } elseif ($name === 'video') {
                $add((string) ($attributes['src'] ?? ''), ['media']);
                $add((string) ($attributes['poster'] ?? ''), ['image']);
            } elseif (in_array($name, ['audio', 'track'], true)) {
                $add((string) ($attributes['src'] ?? ''), ['media']);
            } elseif ($name === 'input') {
                $add((string) ($attributes['src'] ?? ''), ['image']);
            } elseif ($name === 'link') {
                $href = (string) ($attributes['href'] ?? '');
                if (trim($href) === '') {
                    continue;
                }
                $rel = strtolower((string) ($attributes['rel'] ?? ''));
                $as = strtolower((string) ($attributes['as'] ?? ''));
                if (preg_match('/(?:^|\s)stylesheet(?:\s|$)/', $rel) || $as === 'style') {
                    $add($href, ['style']);
                } elseif ($as === 'image') {
                    $add($href, ['image']);
                } elseif ($as === 'font') {
                    $add($href, ['font']);
                } elseif (in_array($as, ['audio', 'video'], true)) {
                    $add($href, ['media']);
                } else {
                    throw ValidationException::withMessages([
                        'tag' => 'Quick Monetize supports external link resources only when their resource type is explicit. Use Advanced setup for custom link/preload behavior.',
                    ]);
                }
            }
        }

        preg_match_all('/\bstyle\s*=\s*(["\'])(.*?)\1/is', $tag, $styleAttributes, PREG_SET_ORDER);
        foreach ($styleAttributes as $styleAttribute) {
            preg_match_all('/url\(\s*(["\']?)(.*?)\1\s*\)/is', (string) ($styleAttribute[2] ?? ''), $styleUrls, PREG_SET_ORDER);
            foreach ($styleUrls as $styleUrl) {
                // CSS url() may address backgrounds or fonts. Both are passive
                // resource classes and neither grants script execution.
                $references[] = [trim((string) ($styleUrl[2] ?? '')), ['image', 'font']];
            }
        }

        $result = [
            'all' => [],
            'frame' => [],
            'image' => [],
            'style' => [],
            'media' => [],
            'font' => [],
        ];
        foreach ($references as [$rawUrl, $types]) {
            $url = html_entity_decode(trim((string) $rawUrl), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($url === '') {
                continue;
            }
            if (str_starts_with($url, '//')) {
                $url = 'https:'.$url;
            }
            if (! filter_var($url, FILTER_VALIDATE_URL)
                || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
                throw ValidationException::withMessages([
                    'tag' => 'Quick Monetize resource URLs must use absolute HTTPS URLs. Use Advanced setup for relative, data, blob, or other custom resource URLs.',
                ]);
            }

            $origin = $validator->canonicalOrigin($url);
            if ($origin === null) {
                $host = $validator->normalizeHost((string) parse_url($url, PHP_URL_HOST));
                throw ValidationException::withMessages([
                    'tag' => "Provider resource host [{$host}] is private, reserved, unresolved, control-plane, or otherwise unsafe for publisher delivery.",
                ]);
            }
            $result['all'][] = $origin;
            foreach ($types as $type) {
                if (array_key_exists($type, $result)) {
                    $result[$type][] = $origin;
                }
            }
        }

        foreach ($result as $key => $origins) {
            $result[$key] = array_values(array_unique($origins));
        }

        return $result;
    }

    /** @return array<string, string> */
    private function htmlAttributes(string $source): array
    {
        $attributes = [];
        preg_match_all('/([A-Za-z_:][-A-Za-z0-9_:.]*)\s*(?:=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/u', $source, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $name = strtolower((string) ($match[1] ?? ''));
            if ($name === '') {
                continue;
            }
            $value = '';
            foreach ([2, 3, 4] as $capture) {
                if (array_key_exists($capture, $match) && (string) $match[$capture] !== '') {
                    $value = (string) $match[$capture];
                    break;
                }
            }
            $attributes[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $attributes;
    }

    private function publisherRevenueShare(Site $site): string
    {
        $share = $site->default_revenue_share_percent;
        if ($share === null) {
            $share = $site->publisher?->applicableRevenueShare();
        }
        if ($share === null) {
            $share = (int) config('reporting.default_publisher_share_bp', 7000) / 100;
        }

        return number_format((float) $share, 4, '.', '');
    }
}
