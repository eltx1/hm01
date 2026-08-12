<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PrebidConfiguredMode;
use App\Enums\PrebidDeliveryMode;
use App\Http\Controllers\Controller;
use App\Models\BidderAccount;
use App\Models\BidderPlacementMapping;
use App\Models\BidderSiteMapping;
use App\Models\GamConnection;
use App\Models\Placement;
use App\Models\PrebidBidder;
use App\Models\PrebidBuild;
use App\Models\PrebidSetupRun;
use App\Models\Site;
use App\Services\Inventory\SiteConfigPublisher;
use App\Services\Prebid\PrebidGamSetupService;
use App\Services\Prebid\PrebidManager;
use App\Services\Serving\SiteEngineStateResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use JsonException;

class PrebidController extends Controller
{
    public function index(
        Site $site,
        SiteEngineStateResolver $engines,
        PrebidManager $manager,
        PrebidGamSetupService $setup,
    ): View {
        $engineState = $engines->resolve($site);
        $connection = $engineState->gamConnection;
        $profileWritable = $engineState->prebidDeliveryMode === PrebidDeliveryMode::Standalone || $connection !== null;
        $settings = $engineState->prebidDeliveryMode === PrebidDeliveryMode::Standalone
            ? $manager->settingsForSite($site)
            : ($connection ? $manager->settingsFor($connection) : $manager->settingsForSite($site));
        $siteMappings = BidderSiteMapping::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->with(['account.bidder.adapter', 'placementMappings.placement'])
            ->orderBy('sequence')
            ->get();

        $gamSetupRelevant = $engineState->prebidDeliveryMode === PrebidDeliveryMode::GamBridge && $connection !== null;

        return view('admin.prebid.index', [
            'site' => $site->load('placements'),
            'connection' => $connection,
            'engineState' => $engineState,
            'profileWritable' => $profileWritable,
            'settings' => $settings,
            'builds' => PrebidBuild::query()->orderByDesc('built_at')->get(),
            'bidders' => PrebidBidder::withoutGlobalScopes()->with('adapter')->where('enabled', true)->orderBy('sort_order')->get(),
            'accounts' => BidderAccount::withoutGlobalScopes()->with('bidder.adapter')->where('organization_id', auth()->user()->organization_id)->orderBy('name')->get(),
            'siteMappings' => $siteMappings,
            'setupPreview' => $gamSetupRelevant ? $setup->preview($connection) : null,
            'setupRuns' => $gamSetupRelevant
                ? PrebidSetupRun::withoutGlobalScopes()->where('gam_connection_id', $connection->id)->latest()->limit(10)->get()
                : collect(),
        ]);
    }

    public function updateSettings(
        Request $request,
        Site $site,
        SiteEngineStateResolver $engines,
        PrebidManager $manager,
        SiteConfigPublisher $publisher,
    ): RedirectResponse {
        $data = $request->validate([
            'delivery_mode' => ['required', Rule::enum(PrebidConfiguredMode::class)],
            'prebid_build_id' => ['nullable', 'ulid', 'exists:prebid_builds,id'],
            'enabled' => ['sometimes', 'boolean'],
            'auction_timeout_ms' => ['nullable', 'integer', 'between:100,5000'],
            'price_granularity' => ['nullable', Rule::in(['low', 'medium', 'high', 'dense', 'auto', 'custom'])],
            'currency' => ['nullable', 'string', 'size:3'],
            'bidder_sequence' => ['nullable', Rule::in(['fixed', 'random'])],
            'consent_json' => ['nullable', 'string', 'max:20000'],
            'lazy_loading' => ['sometimes', 'boolean'],
            'refresh_enabled' => ['sometimes', 'boolean'],
            'refresh_minimum_seconds' => ['nullable', 'integer', 'between:30,3600'],
            'bidder_timeout_reporting' => ['sometimes', 'boolean'],
            'gam_fallback' => ['sometimes', 'boolean'],
        ]);

        $configuredMode = PrebidConfiguredMode::from((string) $data['delivery_mode']);
        $enabled = $request->boolean('enabled');
        $site->update([
            'prebid_enabled' => $enabled,
            'prebid_delivery_mode' => $configuredMode,
        ]);
        $site = $site->refresh();
        $engineState = $engines->resolve($site);

        $runtimeData = [
            'prebid_build_id' => $data['prebid_build_id'] ?? null,
            'enabled' => $enabled,
            'auction_timeout_ms' => (int) ($data['auction_timeout_ms'] ?? config('prebid.default_timeout_ms', 1200)),
            'price_granularity' => (string) ($data['price_granularity'] ?? 'medium'),
            'currency' => strtoupper((string) ($data['currency'] ?? config('prebid.default_currency', 'USD'))),
            'bidder_sequence' => (string) ($data['bidder_sequence'] ?? 'fixed'),
            'consent_behavior' => $this->jsonObject($data['consent_json'] ?? '', 'consent_json'),
            'lazy_loading' => ['enabled' => $request->boolean('lazy_loading', true)],
            'refresh_behavior' => [
                'enabled' => $request->boolean('refresh_enabled', true),
                'minimumIntervalSeconds' => (int) ($data['refresh_minimum_seconds'] ?? 30),
            ],
            'bidder_timeout_reporting' => $request->boolean('bidder_timeout_reporting', true),
            'gam_fallback' => $request->boolean('gam_fallback', true),
        ];

        // Persist the profile selected by the administrator, not a profile
        // inferred indirectly from a later runtime branch. AUTO deliberately
        // follows the central resolver; explicit modes always write their own
        // owner so switching modes never leaves the chosen profile stale.
        $profileDeliveryMode = match ($configuredMode) {
            PrebidConfiguredMode::Standalone => PrebidDeliveryMode::Standalone,
            PrebidConfiguredMode::GamBridge => PrebidDeliveryMode::GamBridge,
            PrebidConfiguredMode::Auto => $engineState->prebidDeliveryMode,
        };

        if ($profileDeliveryMode === PrebidDeliveryMode::Standalone) {
            $manager->updateStandaloneSettings($site, $runtimeData, $request->user());
        } elseif ($engineState->gamConnection !== null) {
            $manager->updateSettings($engineState->gamConnection, $runtimeData, $request->user());
        }

        $engineState = $engines->resolve($site->refresh());
        $version = $publisher->publishActiveProduction($site->refresh(), $request->user());
        $modeSummary = 'Configured '.$configuredMode->value.'; resolved '.$engineState->prebidDeliveryMode->value.'. ';
        if ($configuredMode === PrebidConfiguredMode::GamBridge && $engineState->prebidReason === 'GAM_BRIDGE_CONNECTION_REQUIRED') {
            $modeSummary .= 'ACTION REQUIRED: an eligible GAM connection is required. ';
        }

        return back()->with('status', $modeSummary.($version
            ? 'Production configuration v'.$version->version.' was queued automatically.'
            : 'The settings will publish automatically when the website is activated.'));
    }

    public function storeAccount(Request $request, PrebidManager $manager): RedirectResponse
    {
        $data = $request->validate([
            'prebid_bidder_id' => ['required', 'ulid', 'exists:prebid_bidders,id'],
            'name' => ['required', 'string', 'max:255'],
            'publisher_id' => ['nullable', 'string', 'max:255'],
            'public_parameters_json' => ['nullable', 'string', 'max:20000'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        $data['public_parameters'] = $this->jsonObject($data['public_parameters_json'] ?? '', 'public_parameters_json');
        $data['enabled'] = $request->boolean('enabled', true);
        $manager->addAccount(PrebidBidder::withoutGlobalScopes()->findOrFail($data['prebid_bidder_id']), $data, $request->user());

        return back()->with('status', 'Bidder account saved.');
    }

    public function assignSite(
        Request $request,
        Site $site,
        BidderAccount $bidderAccount,
        PrebidManager $manager,
        SiteConfigPublisher $publisher,
    ): RedirectResponse {
        $data = $request->validate([
            'public_parameters_json' => ['nullable', 'string', 'max:20000'],
            'sequence' => ['nullable', 'integer', 'between:0,1000'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        $data['public_parameters'] = $this->jsonObject($data['public_parameters_json'] ?? '', 'public_parameters_json');
        $data['enabled'] = $request->boolean('enabled', true);
        $manager->assignToSite($bidderAccount, $site, $data, $request->user());
        $publisher->publishActiveProduction($site->refresh(), $request->user());

        return back()->with('status', 'Bidder assigned. Active websites queue production automatically; inactive websites publish on activation.');
    }

    public function assignPlacement(
        Request $request,
        Site $site,
        BidderSiteMapping $bidderSiteMapping,
        Placement $placement,
        PrebidManager $manager,
        SiteConfigPublisher $publisher,
    ): RedirectResponse {
        abort_unless($bidderSiteMapping->site_id === $site->id && $placement->site_id === $site->id, 404);
        $data = $request->validate([
            'placement_id_value' => ['nullable', 'string', 'max:255'],
            'public_parameters_json' => ['nullable', 'string', 'max:20000'],
            'sequence' => ['nullable', 'integer', 'between:0,1000'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        $data['public_parameters'] = $this->jsonObject($data['public_parameters_json'] ?? '', 'public_parameters_json');
        $data['enabled'] = $request->boolean('enabled', true);
        $manager->assignToPlacement($bidderSiteMapping, $placement, $data, $request->user());
        $publisher->publishActiveProduction($site->refresh(), $request->user());

        return back()->with('status', 'Bidder placement parameters saved and queued automatically when the website is active.');
    }

    public function toggleSiteMapping(
        Request $request,
        Site $site,
        BidderSiteMapping $bidderSiteMapping,
        PrebidManager $manager,
        SiteConfigPublisher $publisher,
    ): RedirectResponse {
        abort_unless($bidderSiteMapping->site_id === $site->id, 404);
        $manager->toggle($bidderSiteMapping, $request->boolean('enabled'), $request->user());
        $publisher->publishActiveProduction($site->refresh(), $request->user());

        return back()->with('status', 'Bidder website mapping updated without changing publisher code.');
    }

    public function togglePlacementMapping(
        Request $request,
        Site $site,
        BidderPlacementMapping $bidderPlacementMapping,
        PrebidManager $manager,
        SiteConfigPublisher $publisher,
    ): RedirectResponse {
        $bidderPlacementMapping->loadMissing('placement');
        abort_unless($bidderPlacementMapping->placement?->site_id === $site->id, 404);
        $manager->toggle($bidderPlacementMapping, $request->boolean('enabled'), $request->user());
        $publisher->publishActiveProduction($site->refresh(), $request->user());

        return back()->with('status', 'Bidder placement mapping updated without changing publisher code.');
    }

    public function setup(
        Request $request,
        GamConnection $gamConnection,
        PrebidGamSetupService $setup,
    ): RedirectResponse {
        $data = $request->validate([
            'dry_run' => ['required', 'boolean'],
            'confirm_external_writes' => ['sometimes', 'boolean'],
        ]);
        $run = $setup->start(
            $gamConnection,
            $request->user(),
            (bool) $data['dry_run'],
            $request->boolean('confirm_external_writes'),
        );

        return back()->with($run->status === 'FAILED' ? 'error' : 'status', 'Prebid GAM setup run '.$run->status.'; '.$run->completed_objects.'/'.$run->estimated_objects.' objects complete.');
    }

    public function resume(
        Request $request,
        PrebidSetupRun $prebidSetupRun,
        PrebidGamSetupService $setup,
    ): RedirectResponse {
        $request->validate(['confirm_external_writes' => ['accepted']]);
        $run = $setup->resume($prebidSetupRun, $request->user(), true);

        return back()->with($run->status === 'FAILED' ? 'error' : 'status', 'Prebid GAM setup resumed: '.$run->status.'.');
    }

    private function jsonObject(string $value, string $field): array
    {
        if (trim($value) === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw \Illuminate\Validation\ValidationException::withMessages([$field => 'The JSON object is invalid.']);
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw \Illuminate\Validation\ValidationException::withMessages([$field => 'The value must be a JSON object.']);
        }

        return $decoded;
    }
}
