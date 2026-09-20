<?php

namespace App\Services\Demand;

use App\Enums\ConfigEnvironment;
use App\Enums\DemandAccountScope;
use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Models\DemandAccount;
use App\Models\DemandNetwork;
use App\Models\DemandPlacement;
use App\Models\DemandSite;
use App\Models\DemandWidget;
use App\Models\Placement;
use App\Models\Publisher;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Inventory\PlacementPresetBuilder;
use App\Services\Inventory\SiteConfigurationBuilder;
use App\Services\Inventory\SiteConfigPublisher;
use App\Services\Operations\PlatformControlService;
use App\Services\Security\PublicProviderOriginValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class QuickMonetizeService
{
    public function __construct(
        private readonly DemandAccountService $accounts,
        private readonly DemandConnectorManager $connectors,
        private readonly DirectTagRecipeParser $parser,
        private readonly SiteConfigurationBuilder $siteConfigurationBuilder,
        private readonly SiteConfigPublisher $configPublisher,
        private readonly PlatformControlService $controls,
        private readonly AuditRecorder $audit,
        private readonly PlacementPresetBuilder $placements,
        private readonly PublicProviderOriginValidator $originValidator,
        private readonly VastTagUrlParser $vastTags,
    ) {}

    /** @return array{account:DemandAccount,placement:Placement,placements:array<int,Placement>} */
    public function activate(Site $site, DemandNetwork $network, User $actor, string $tag, ?Placement $existingPlacement = null, ?string $preset = null, ?string $placementName = null): array
    {
        $tag = trim($tag);
        try {
            $rewardedPath = (new GoogleRewardedAdUnitPath())->parse($tag);
            $vast = $this->vastTags->parse($tag);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['tag' => $exception->getMessage()]);
        }

        if ($rewardedPath !== null) {
            if ($existingPlacement === null && $preset !== 'rewarded') {
                throw ValidationException::withMessages(['placement_preset' => 'A GAM ad unit path requires Rewarded Video / Opt-in.']);
            }
            $tag = $rewardedPath;
            $scriptOrigins = ['https://securepubads.g.doubleclick.net'];
            $resourceOrigins = ['all' => $scriptOrigins, 'frame' => [], 'image' => [], 'style' => [], 'media' => [], 'font' => []];
        } elseif ($vast !== null) {
            $tag = $vast['url'];
            // A URL-only Quick activation is unambiguously the VAST path. When
            // Horus is creating the surface, select the floating player even if
            // the form/API client left the ordinary display default in place.
            if ($existingPlacement === null && ! in_array($preset, ['video_floating', 'video_outstream', 'rewarded'], true)) {
                $preset = 'video_floating';
            }
            $scriptOrigins = ['https://imasdk.googleapis.com'];
            $resourceOrigins = [
                'all' => [$vast['origin'], 'https://imasdk.googleapis.com'],
                'frame' => [],
                'image' => [],
                'style' => [],
                'media' => [$vast['origin']],
                'font' => [],
            ];
        } else {
            $review = $this->parser->parse($tag);
            $warnings = array_values((array) ($review['securityWarnings'] ?? []));
            if ((bool) ($review['containsSensitiveMaterial'] ?? false) || $warnings !== []) {
                throw ValidationException::withMessages(['tag' => $warnings !== [] ? implode(' ', $warnings) : 'The supplied public tag contains material that cannot be published safely.']);
            }

            $scriptOrigins = $this->scriptOrigins((array) ($review['detectedScripts'] ?? []));
            $resourceOrigins = $this->resourceOrigins($tag);
        }
        $isolationOrigins = collect($scriptOrigins)->merge($resourceOrigins['all'])->unique()->values()->all();
        if ($isolationOrigins === []) throw ValidationException::withMessages(['tag' => 'Quick Monetize requires at least one reviewed HTTPS provider script or resource URL.']);
        if (count($isolationOrigins) > 20) throw ValidationException::withMessages(['tag' => 'Quick Monetize supports at most 20 distinct provider resource origins per tag. Use Advanced setup for more complex provider tags.']);
        if ($existingPlacement) $this->assertPlacementReady($site, $existingPlacement);

        return DB::transaction(function () use ($site, $network, $actor, $tag, $vast, $rewardedPath, $scriptOrigins, $resourceOrigins, $isolationOrigins, $existingPlacement, $preset, $placementName): array {
            $bundle = ($existingPlacement === null && $preset === 'responsive_display')
                || data_get($existingPlacement?->metadata, 'responsive_bundle') === 'v1';
            $placement = $existingPlacement;
            if ($bundle) {
                $placements = $this->placements->responsiveBundle($site, $actor, $placementName);
            } elseif (! $placement) {
                if (! $preset) throw ValidationException::withMessages(['placement_preset' => 'Choose an ad format / surface.']);
                $placement = $this->placements->create($site, $preset, $actor, ['name' => $placementName], false, true);
            }
            $placements ??= [$placement];
            foreach ($placements as $placement) $this->assertPlacementReady($site, $placement);

            $siteRevenueShare = $this->publisherRevenueShare($site);
            $publisher = Publisher::withoutGlobalScopes()->whereKey($site->publisher_id)->lockForUpdate()->firstOrFail();
            $account = DemandAccount::withoutGlobalScopes()->whereNull('deleted_at')->where('demand_network_id', $network->id)->where('publisher_id', $publisher->id)->where('scope', DemandAccountScope::Publisher->value)->get()->first(fn (DemandAccount $candidate) => (bool) data_get($candidate->configuration, 'quick_monetize_managed', false));

            if (! $account) {
                $account = $this->accounts->create(['demand_network_id' => $network->id, 'publisher_id' => $publisher->id, 'name' => 'Quick Manual Tags · '.$publisher->display_name, 'scope' => DemandAccountScope::Publisher, 'integration_mode' => DemandIntegrationMode::ManualTag, 'approval_status' => DemandApprovalStatus::Approved, 'is_enabled' => true, 'is_default' => false, 'revenue_share_percent' => $siteRevenueShare, 'fallback_priority' => 100, 'account_identifier' => null, 'configuration' => ['quick_monetize_managed' => true, 'allowed_script_origins' => $scriptOrigins, 'render_timeout_ms' => 2500]], $actor);
            } else {
                $configuration = (array) ($account->configuration ?? []);
                $configuration['quick_monetize_managed'] = true;
                $configuration['allowed_script_origins'] = collect((array) data_get($configuration, 'allowed_script_origins', []))->merge($scriptOrigins)->unique()->values()->all();
                $configuration['render_timeout_ms'] ??= 2500;
                unset($configuration['direct_recipe']);
                $account = $this->accounts->update($account, ['integration_mode' => DemandIntegrationMode::ManualTag, 'approval_status' => DemandApprovalStatus::Approved, 'is_enabled' => true, 'configuration' => $configuration], $actor);
            }

            $existingDemandSite = DemandSite::withoutGlobalScopes()->where('demand_account_id', $account->id)->where('site_id', $site->id)->first();
            $siteMappingConfiguration = (array) ($existingDemandSite?->configuration ?? []);
            $siteMappingConfiguration['quick_monetize_managed'] = true;
            $demandSite = $this->accounts->assignSite($account, $site, ['approval_status' => DemandApprovalStatus::Approved->value, 'is_enabled' => true, 'is_default' => true, 'integration_mode' => DemandIntegrationMode::ManualTag->value, 'revenue_share_percent' => $siteRevenueShare, 'fallback_priority' => 100, 'remote_site_id' => $existingDemandSite?->remote_site_id, 'configuration' => $siteMappingConfiguration], $actor);

            foreach ($placements as $placement) {
                $existingDemandPlacement = DemandPlacement::withoutGlobalScopes()->where('demand_site_id', $demandSite->id)->where('placement_id', $placement->id)->first();
                $placementMappingConfiguration = (array) ($existingDemandPlacement?->configuration ?? []);
                $placementMappingConfiguration['quick_monetize_managed'] = true;
                $demandPlacement = $this->accounts->assignPlacement($demandSite, $placement, ['approval_status' => DemandApprovalStatus::Approved->value, 'is_enabled' => true, 'integration_mode' => DemandIntegrationMode::ManualTag->value, 'fallback_priority' => 100, 'remote_placement_id' => $existingDemandPlacement?->remote_placement_id, 'placement_code' => $existingDemandPlacement?->placement_code ?? $placement->code, 'configuration' => $placementMappingConfiguration], $actor);

                $existingQuickWidget = DemandWidget::withoutGlobalScopes()->where('demand_placement_id', $demandPlacement->id)->get()->filter(fn (DemandWidget $widget) => (bool) data_get($widget->configuration, 'quick_monetize_managed', false))->sortByDesc('id')->first();
                $widgetConfiguration = (array) ($existingQuickWidget?->configuration ?? []);
                $widgetConfiguration['quick_monetize_managed'] = true;
                $widgetConfiguration['isolation_allowed_origins'] = $isolationOrigins;
                $widgetConfiguration['isolation_script_origins'] = $scriptOrigins;
                $widgetConfiguration['isolation_frame_origins'] = $resourceOrigins['frame'];
                $widgetConfiguration['isolation_image_origins'] = $resourceOrigins['image'];
                $widgetConfiguration['isolation_style_origins'] = $resourceOrigins['style'];
                $widgetConfiguration['isolation_media_origins'] = $resourceOrigins['media'];
                $widgetConfiguration['isolation_font_origins'] = $resourceOrigins['font'];
                $widgetConfiguration['input_kind'] = $rewardedPath !== null ? 'GAM_REWARDED_PATH' : ($vast !== null ? 'VAST_URL' : 'PROVIDER_TAG');
                if ($vast !== null) {
                    $widgetConfiguration['vast_origin'] = $vast['origin'];
                    $widgetConfiguration['render_timeout_ms'] = max(15_000, (int) ($widgetConfiguration['render_timeout_ms'] ?? 0));
                } else {
                    unset($widgetConfiguration['vast_origin']);
                    // Provider tags commonly render after an asynchronous auction or
                    // consent callback. Give the isolated runtime a practical window
                    // while still keeping the loader's failover strictly bounded.
                    $widgetConfiguration['render_timeout_ms'] = max(10_000, (int) ($widgetConfiguration['render_timeout_ms'] ?? 0));
                }
                $this->accounts->upsertWidget($demandPlacement, ['name' => $existingQuickWidget?->name ?? 'Quick Manual · '.$placement->code, 'widget_code' => 'quick-'.$placement->code, 'integration_mode' => DemandIntegrationMode::ManualTag->value, 'direct_tag_template' => $tag, 'approval_status' => DemandApprovalStatus::Approved->value, 'is_enabled' => true, 'configuration' => $widgetConfiguration], $actor);

                if (! (bool) $site->native_demand_enabled) {
                    $site->update(['native_demand_enabled' => true]);
                    $this->audit->record('demand.site.direct_demand_enabled_changed', $site->organization_id, $actor, $site, ['native_demand_enabled' => false], ['native_demand_enabled' => true]);
                }

                $demandPlacement = DemandPlacement::withoutGlobalScopes()->with(['demandSite.account.network', 'placement.sizes', 'widgets'])->findOrFail($demandPlacement->id);
                $connector = $this->connectors->for($account->refresh()->load('network'));
                $connectorReview = $connector->parseDirectTag($tag);
                if (! (bool) ($connectorReview['safe'] ?? false)) {
                    $connectorWarnings = array_values((array) ($connectorReview['securityWarnings'] ?? []));
                    throw ValidationException::withMessages(['tag' => $connectorWarnings !== [] ? implode(' ', $connectorWarnings) : 'The supplied third-party tag did not pass the connector security review.']);
                }
                try { $recipe = $connector->generateDirectTag($demandPlacement); }
                catch (ValidationException $exception) { throw $exception; }
                catch (Throwable $exception) { throw ValidationException::withMessages(['tag' => $exception->getMessage() !== '' ? $exception->getMessage() : 'Quick Monetize could not create a trusted runtime recipe. No changes were published.']); }

                $reviewMode = strtoupper((string) data_get($connectorReview, 'recipe.executionMode', ''));
                $actualMode = strtoupper((string) ($recipe['executionMode'] ?? ''));
                if (! in_array($actualMode, ['STRUCTURED', 'ISOLATED_IFRAME'], true) || $actualMode !== $reviewMode || (data_get($connectorReview, 'recipe.provider') === 'GOOGLE_GPT' && $actualMode !== 'STRUCTURED')) throw ValidationException::withMessages(['tag' => 'Quick Monetize could not create the reviewed trusted runtime recipe. No changes were published.']);
            }

            $finalConfig = $this->siteConfigurationBuilder->build($site->fresh(), ConfigEnvironment::Production, 0);
            foreach ($placements as $placement) {
                $finalPlacement = collect((array) ($finalConfig['placements'] ?? []))->first(fn (array $candidate) => ($candidate['code'] ?? null) === $placement->code);
                $directCandidates = (array) ($finalConfig['directDemand']['placements'][$placement->code]['candidates'] ?? []);
                if ($directCandidates === [] || ! is_array($finalPlacement)) throw ValidationException::withMessages(['tag' => 'The tag passed parsing but could not produce a deliverable Direct Demand candidate. No changes were published.']);
                if ((bool) ($finalPlacement['rendererConflict'] ?? false) || ($finalPlacement['renderer'] ?? null) !== 'DIRECT_JS' || ! (bool) ($finalPlacement['enabled'] ?? false)) throw ValidationException::withMessages([$existingPlacement ? 'placement_id' : 'placement_preset' => 'This placement is already owned by another renderer or is not eligible for Direct Demand. Quick Monetize will not replace or double-render it.']);
            }
            $this->configPublisher->publishActiveProduction($site->fresh(), $actor);
            return ['account' => $account->refresh(), 'placement' => $placements[0]->refresh()->load(['sizes', 'adFormat']), 'placements' => $placements];
        });
    }

    private function assertPlacementReady(Site $site, Placement $placement): void
    {
        $problems = [];
        if ($this->controls->disabledForSite('AD_SERVING', $site->id, $site->gam_connection_id)) $problems[] = 'Ad serving is paused for this website.';
        if ($this->controls->disabledForSite('DIRECT_JS', $site->id)) $problems[] = 'Direct Demand is paused for this website.';
        if ($this->controls->disabledForSite('NATIVE_DEMAND', $site->id)) $problems[] = 'The website Direct Demand compatibility control is paused.';
        if ($this->controls->placementEngineDisabled($placement->id, 'DIRECT_JS')) $problems[] = 'Direct Demand is paused for this placement.';
        if ($this->controls->placementEngineDisabled($placement->id, 'AD_SERVING')) $problems[] = 'Ad serving is paused for this placement.';
        if ($problems !== []) throw ValidationException::withMessages(['quick' => implode(' ', array_values(array_unique($problems)))]);
    }

    private function scriptOrigins(array $scripts): array
    {
        $origins = [];
        foreach ($scripts as $script) {
            $url = trim((string) ($script['url'] ?? '')); if (str_starts_with($url, '//')) $url = 'https:'.$url;
            if (! filter_var($url, FILTER_VALIDATE_URL) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') throw ValidationException::withMessages(['tag' => 'Provider script URLs must use HTTPS.']);
            $origin = $this->originValidator->canonicalOrigin($url);
            if ($origin === null) { $host = $this->originValidator->normalizeHost((string) parse_url($url, PHP_URL_HOST)); throw ValidationException::withMessages(['tag' => "Provider script host [{$host}] is private, reserved, unresolved, control-plane, or otherwise unsafe for publisher delivery."]); }
            $origins[] = $origin;
        }
        return array_values(array_unique($origins));
    }

    private function resourceOrigins(string $tag): array
    {
        $references = [];
        preg_match_all('/<\s*(img|iframe|source|video|audio|track|input|link)\b([^>]*)>/is', $tag, $elements, PREG_SET_ORDER);
        foreach ($elements as $element) {
            $name = strtolower((string) ($element[1] ?? '')); $attributes = $this->htmlAttributes((string) ($element[2] ?? ''));
            $add = function (string $value, array $types, bool $srcset = false) use (&$references): void {
                $value = trim($value); if ($value === '') return;
                if ($srcset) { foreach (preg_split('/\s*,\s*/', $value) ?: [] as $candidate) { $parts = preg_split('/\s+/', trim((string) $candidate), 2); if (($parts[0] ?? '') !== '') $references[] = [(string) $parts[0], $types]; } return; }
                $references[] = [$value, $types];
            };
            if ($name === 'img') { $add((string) ($attributes['src'] ?? ''), ['image']); $add((string) ($attributes['srcset'] ?? ''), ['image'], true); }
            elseif ($name === 'iframe') $add((string) ($attributes['src'] ?? ''), ['frame']);
            elseif ($name === 'source') { $add((string) ($attributes['src'] ?? ''), ['image', 'media']); $add((string) ($attributes['srcset'] ?? ''), ['image', 'media'], true); }
            elseif ($name === 'video') { $add((string) ($attributes['src'] ?? ''), ['media']); $add((string) ($attributes['poster'] ?? ''), ['image']); }
            elseif (in_array($name, ['audio', 'track'], true)) $add((string) ($attributes['src'] ?? ''), ['media']);
            elseif ($name === 'input') $add((string) ($attributes['src'] ?? ''), ['image']);
            elseif ($name === 'link') {
                $href = (string) ($attributes['href'] ?? ''); if (trim($href) === '') continue;
                $rel = strtolower((string) ($attributes['rel'] ?? '')); $as = strtolower((string) ($attributes['as'] ?? ''));
                if (preg_match('/(?:^|\s)stylesheet(?:\s|$)/', $rel) || $as === 'style') $add($href, ['style']);
                elseif ($as === 'image') $add($href, ['image']); elseif ($as === 'font') $add($href, ['font']); elseif (in_array($as, ['audio', 'video'], true)) $add($href, ['media']);
                else throw ValidationException::withMessages(['tag' => 'Quick Monetize supports external link resources only when their resource type is explicit. Use Advanced setup for custom link/preload behavior.']);
            }
        }
        preg_match_all('/\bstyle\s*=\s*(["\'])(.*?)\1/is', $tag, $styleAttributes, PREG_SET_ORDER);
        foreach ($styleAttributes as $styleAttribute) { preg_match_all('/url\(\s*(["\']?)(.*?)\1\s*\)/is', (string) ($styleAttribute[2] ?? ''), $styleUrls, PREG_SET_ORDER); foreach ($styleUrls as $styleUrl) $references[] = [trim((string) ($styleUrl[2] ?? '')), ['image', 'font']]; }
        $result = ['all' => [], 'frame' => [], 'image' => [], 'style' => [], 'media' => [], 'font' => []];
        foreach ($references as [$rawUrl, $types]) {
            $url = html_entity_decode(trim((string) $rawUrl), ENT_QUOTES | ENT_HTML5, 'UTF-8'); if ($url === '') continue; if (str_starts_with($url, '//')) $url = 'https:'.$url;
            if (! filter_var($url, FILTER_VALIDATE_URL) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') throw ValidationException::withMessages(['tag' => 'Quick Monetize resource URLs must use absolute HTTPS URLs. Use Advanced setup for relative, data, blob, or other custom resource URLs.']);
            $origin = $this->originValidator->canonicalOrigin($url);
            if ($origin === null) { $host = $this->originValidator->normalizeHost((string) parse_url($url, PHP_URL_HOST)); throw ValidationException::withMessages(['tag' => "Provider resource host [{$host}] is private, reserved, unresolved, control-plane, or otherwise unsafe for publisher delivery."]); }
            $result['all'][] = $origin; foreach ($types as $type) if (array_key_exists($type, $result)) $result[$type][] = $origin;
        }
        foreach ($result as $key => $origins) $result[$key] = array_values(array_unique($origins));
        return $result;
    }

    private function htmlAttributes(string $source): array
    {
        $attributes = [];
        preg_match_all('/([A-Za-z_:][-A-Za-z0-9_:.]*)\s*(?:=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/u', $source, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        foreach ($matches as $match) { $name = strtolower((string) ($match[1] ?? '')); if ($name === '') continue; $value = $match[2] ?? $match[3] ?? $match[4] ?? ''; $attributes[$name] = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
        return $attributes;
    }

    private function publisherRevenueShare(Site $site): string
    {
        $share = $site->default_revenue_share_percent; if ($share === null) $share = $site->publisher?->applicableRevenueShare(); if ($share === null) $share = (int) config('reporting.default_publisher_share_bp', 7000) / 100;
        return number_format((float) $share, 4, '.', '');
    }
}
