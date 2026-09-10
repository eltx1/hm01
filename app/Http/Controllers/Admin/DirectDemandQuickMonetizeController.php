<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DemandAccountScope;
use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Enums\DemandNetworkCode;
use App\Enums\PlacementStatus;
use App\Enums\SiteStatus;
use App\Http\Controllers\Controller;
use App\Models\DemandAccount;
use App\Models\DemandNetwork;
use App\Models\DemandPlacement;
use App\Models\Placement;
use App\Models\Site;
use App\Services\Demand\DemandAccountService;
use App\Services\Demand\DemandConfigurationBuilder;
use App\Services\Demand\DemandConnectorManager;
use App\Services\Demand\DirectTagRecipeParser;
use App\Services\Inventory\SiteConfigPublisher;
use App\Services\Operations\PlatformControlService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

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
                    ->where('status', PlacementStatus::Active->value)
                    ->with('sizes')
                    ->orderBy('sort_order')
                    ->orderBy('name'),
            ])
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
        DemandConfigurationBuilder $configurationBuilder,
        SiteConfigPublisher $configPublisher,
        PlatformControlService $controls,
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

        $site = Site::withoutGlobalScopes()->with('publisher')->findOrFail($data['site_id']);
        $placement = Placement::withoutGlobalScopes()->findOrFail($data['placement_id']);

        if ($site->status !== SiteStatus::Active) {
            throw ValidationException::withMessages(['site_id' => 'Quick Monetize is available only for active websites.']);
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
            $configurationBuilder,
            $configPublisher,
            $network,
            $site,
            $placement,
            $tag,
            $origins,
        ): DemandAccount {
            $actor = $request->user();
            $publisher = $site->publisher;

            $account = DemandAccount::withoutGlobalScopes()
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

            $accounts->upsertWidget($demandPlacement, [
                'name' => 'Quick Manual · '.$placement->code,
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

            $site->update(['native_demand_enabled' => true]);

            $demandPlacement = DemandPlacement::withoutGlobalScopes()
                ->with(['demandSite.account.network', 'placement.sizes', 'widgets'])
                ->findOrFail($demandPlacement->id);
            $recipe = $connectors->for($account->refresh()->load('network'))->generateDirectTag($demandPlacement);
            if (($recipe['executionMode'] ?? null) !== 'ISOLATED_IFRAME') {
                throw new RuntimeException('Quick Monetize did not produce the expected isolated third-party recipe.');
            }

            $publicConfig = $configurationBuilder->build($site->fresh());
            if ((array) data_get($publicConfig, 'placements.'.$placement->code.'.candidates', []) === []) {
                throw ValidationException::withMessages([
                    'tag' => 'The tag passed parsing but could not produce a deliverable Direct Demand candidate. No changes were published.',
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

    /** @param array<int, array<string, mixed>> $scripts
     *  @return array<int, string>
     */
    private function scriptOrigins(array $scripts): array
    {
        $origins = collect($scripts)
            ->map(function (array $script): ?string {
                $url = trim((string) ($script['url'] ?? ''));
                $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                $port = parse_url($url, PHP_URL_PORT);
                if ($scheme !== 'https' || $host === '') {
                    return null;
                }
                if ($host === 'app.horusmedia.net' || str_ends_with($host, '.app.horusmedia.net')) {
                    return null;
                }

                return $scheme.'://'.$host.($port ? ':'.$port : '');
            })
            ->filter()
            ->unique()
            ->values();

        if ($origins->count() > 20) {
            throw ValidationException::withMessages(['tag' => 'The tag uses more than 20 script origins. Use Advanced setup for manual review.']);
        }

        return $origins->all();
    }

    private function publisherRevenueShare(Site $site): float
    {
        if ($site->default_revenue_share_percent !== null) {
            return (float) $site->default_revenue_share_percent;
        }

        return (float) $site->publisher->applicableRevenueShare();
    }
}
