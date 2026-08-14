<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FinancialReportingMethod;
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
use App\Models\PrebidSetting;
use App\Models\PrebidSetupRun;
use App\Models\ReportSource;
use App\Models\Site;
use App\Services\Audit\AuditRecorder;
use App\Services\Inventory\SiteConfigPublisher;
use App\Services\Operations\PlatformControlService;
use App\Services\Prebid\PrebidGamSetupService;
use App\Services\Prebid\PrebidManager;
use App\Services\Reporting\MonetizationFinancialBindingService;
use App\Services\Reporting\MonetizationFinancialReadinessService;
use App\Services\Serving\SiteEngineStateResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use JsonException;

class PrebidController extends Controller
{
    public function index(
        Site $site,
        PrebidManager $manager,
        PrebidGamSetupService $setup,
        SiteEngineStateResolver $engines,
        MonetizationFinancialReadinessService $financialReadiness,
    ): View {
        $engineState = $engines->resolve($site);
        $connection = $engineState->gamConnection;
        $settings = $engineState->prebidDeliveryMode === PrebidDeliveryMode::Standalone
            ? $manager->settingsForSite($site)
            : ($connection ? $manager->settingsFor($connection) : new PrebidSetting([
                'enabled' => false,
                'prebid_build_id' => PrebidBuild::query()->where('is_active', true)->latest('built_at')->value('id'),
                'auction_timeout_ms' => config('prebid.default_timeout_ms', 1200),
                'price_granularity' => 'medium',
                'currency' => config('prebid.default_currency', 'USD'),
                'bidder_sequence' => 'fixed',
                'consent_behavior' => [],
                'lazy_loading' => ['enabled' => true],
                'refresh_behavior' => ['enabled' => true, 'minimumIntervalSeconds' => 30],
                'bidder_timeout_reporting' => true,
                'gam_fallback' => true,
            ]));
        $siteMappings = BidderSiteMapping::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->with(['account.bidder.adapter', 'placementMappings.placement'])
            ->orderBy('sequence')
            ->get();
        $accounts = BidderAccount::withoutGlobalScopes()
            ->with(['bidder.adapter', 'financialBinding.source', 'financialBinding.connection'])
            ->where('organization_id', auth()->user()->organization_id)
            ->orderBy('name')
            ->get();

        return view('admin.prebid.index', [
            'site' => $site->load('placements'),
            'connection' => $connection,
            'engineState' => $engineState,
            'settings' => $settings,
            'builds' => PrebidBuild::query()->orderByDesc('built_at')->get(),
            'bidders' => PrebidBidder::withoutGlobalScopes()->with('adapter')->where('enabled', true)->orderBy('sort_order')->get(),
            'accounts' => $accounts,
            'financialStatuses' => $accounts->mapWithKeys(fn ($account) => [$account->id => $financialReadiness->status($account)]),
            'reportSources' => ReportSource::query()->where('is_enabled', true)->orderBy('name')->get(),
            'financialMethods' => FinancialReportingMethod::cases(),
            'siteMappings' => $siteMappings,
            'setupPreview' => $connection && $engineState->prebidDeliveryMode === PrebidDeliveryMode::GamBridge ? $setup->preview($connection) : null,
            'setupRuns' => $connection && $engineState->prebidDeliveryMode === PrebidDeliveryMode::GamBridge
                ? PrebidSetupRun::withoutGlobalScopes()->where('gam_connection_id', $connection->id)->latest()->limit(10)->get()
                : collect(),
        ]);
    }

    public function updateFinancialSource(
        Request $request,
        BidderAccount $bidderAccount,
        MonetizationFinancialBindingService $bindings,
    ): RedirectResponse {
        $data = $request->validate([
            'report_source_id' => ['required', 'ulid', 'exists:report_sources,id'],
            'reporting_method' => ['required', Rule::enum(FinancialReportingMethod::class)],
            'currency' => ['required', 'string', 'size:3'],
            'timezone' => ['required', 'timezone'],
            'is_enabled' => ['required', 'boolean'],
            'configuration_json' => ['nullable', 'json'],
        ]);
        $bindings->bind(
            $bidderAccount,
            ReportSource::query()->findOrFail($data['report_source_id']),
            FinancialReportingMethod::from($data['reporting_method']),
            $data['currency'],
            $data['timezone'],
            $request->user(),
            isset($data['configuration_json']) ? (array) json_decode($data['configuration_json'], true, 512, JSON_THROW_ON_ERROR) : [],
            (bool) $data['is_enabled'],
        );

        return back()->with('status', 'Bidder canonical financial source binding updated.');
    }

    public function updateSettings(
        Request $request,
        Site $site,
        PrebidManager $manager,
        SiteConfigPublisher $publisher,
        SiteEngineStateResolver $engines,
        PlatformControlService $controls,
        AuditRecorder $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'prebid_build_id' => ['nullable', 'ulid', 'exists:prebid_builds,id'],
            'enabled' => ['sometimes', 'boolean'],
            'prebid_configured_mode' => ['required', Rule::enum(PrebidConfiguredMode::class)],
            'auction_timeout_ms' => ['required', 'integer', 'between:100,5000'],
            'price_granularity' => ['required', Rule::in(['low', 'medium', 'high', 'dense', 'auto', 'custom'])],
            'currency' => ['required', 'string', 'size:3'],
            'bidder_sequence' => ['required', Rule::in(['fixed', 'random'])],
            'consent_json' => ['nullable', 'string', 'max:20000'],
            'lazy_loading' => ['sometimes', 'boolean'],
            'refresh_enabled' => ['sometimes', 'boolean'],
            'refresh_minimum_seconds' => ['required', 'integer', 'between:30,3600'],
            'bidder_timeout_reporting' => ['sometimes', 'boolean'],
            'gam_fallback' => ['sometimes', 'boolean'],
        ]);
        $data['enabled'] = $request->boolean('enabled');
        $data['consent_behavior'] = $this->jsonObject($data['consent_json'] ?? '', 'consent_json');
        $data['lazy_loading'] = ['enabled' => $request->boolean('lazy_loading')];
        $data['refresh_behavior'] = ['enabled' => $request->boolean('refresh_enabled'), 'minimumIntervalSeconds' => (int) $data['refresh_minimum_seconds']];
        $data['bidder_timeout_reporting'] = $request->boolean('bidder_timeout_reporting');
        $data['gam_fallback'] = $request->boolean('gam_fallback');
        unset($data['consent_json'], $data['refresh_minimum_seconds']);

        $configuredMode = PrebidConfiguredMode::from($data['prebid_configured_mode']);
        unset($data['prebid_configured_mode']);

        $site->loadMissing('servingSettings');
        $currentConfiguredMode = $site->servingSettings?->prebid_configured_mode ?? PrebidConfiguredMode::Auto;
        $beforeState = $engines->resolve($site);
        $connection = $beforeState->gamConnection;
        $bridgeAvailableForConfiguration = $beforeState->gamRequired
            && $connection !== null
            && $connection->is_enabled
            && ! $controls->disabledForSite('GAM', $site->id, $connection->id);
        $existingBridgeProfileCanBeManaged = $currentConfiguredMode === PrebidConfiguredMode::GamBridge
            && ($connection !== null || ! $data['enabled']);
        if ($configuredMode === PrebidConfiguredMode::GamBridge
            && ! $bridgeAvailableForConfiguration
            && ! $existingBridgeProfileCanBeManaged) {
            throw ValidationException::withMessages([
                'prebid_configured_mode' => 'GAM_BRIDGE requires an eligible enabled GAM connection when selecting a new bridge. No Prebid mode or runtime settings were changed.',
            ]);
        }

        $before = [
            'prebid_enabled' => (bool) $site->prebid_enabled,
            'prebid_configured_mode' => $currentConfiguredMode->value,
        ];

        $site->update(['prebid_enabled' => $data['enabled']]);
        $servingSettings = $site->servingSettings()->updateOrCreate(
            ['site_id' => $site->id],
            [
                'organization_id' => $site->organization_id,
                'serving_mode' => $site->serving_mode,
                'prebid_enabled' => $data['enabled'],
                'prebid_configured_mode' => $configuredMode,
            ],
        );
        $after = [
            'prebid_enabled' => (bool) $data['enabled'],
            'prebid_configured_mode' => $configuredMode->value,
        ];
        if ($before !== $after) {
            $audit->record(
                'prebid.site_configuration.updated',
                $site->organization_id,
                $request->user(),
                $servingSettings,
                $before,
                $after,
                ['site_id' => $site->id],
            );
        }

        $site = $site->refresh()->load('servingSettings');
        $engineState = $engines->resolve($site);
        $profileUpdated = false;
        if ($configuredMode === PrebidConfiguredMode::Auto
            && $engineState->prebidDeliveryMode === PrebidDeliveryMode::GamBridge
            && $engineState->prebidReason === 'GAM_BRIDGE_CONNECTION_REQUIRED') {
            // AUTO with unavailable/disabled GAM uses the submitted values to
            // establish the standalone profile, then resolves again.
            $manager->updateStandaloneSettings($site, $data, $request->user());
            $profileUpdated = true;
            $engineState = $engines->resolve($site->refresh()->load('servingSettings'));
        } elseif ($engineState->prebidDeliveryMode === PrebidDeliveryMode::Standalone) {
            $manager->updateStandaloneSettings($site, $data, $request->user());
            $profileUpdated = true;
            $engineState = $engines->resolve($site->refresh()->load('servingSettings'));
        } elseif ($engineState->gamConnection !== null) {
            $manager->updateSettings($engineState->gamConnection, $data, $request->user());
            $profileUpdated = true;
        }

        $version = $publisher->publishActiveProduction($site->refresh(), $request->user());
        $message = 'Prebid website configuration saved. Configured '.$configuredMode->value.'; resolved '.$engineState->prebidDeliveryMode->value.'. ';
        $message .= $profileUpdated
            ? 'Runtime profile updated. '
            : 'No runtime profile was changed because no current profile owner is available. ';
        $message .= $version
            ? 'Production configuration v'.$version->version.' was queued automatically.'
            : 'The production configuration will publish automatically when the website is activated.';

        return back()->with('status', $message);
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

    public function updateBidderPrivacy(Request $request, PrebidBidder $prebidBidder, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate([
            'tcf' => ['required', Rule::in(['UNKNOWN', 'SUPPORTED', 'NOT_SUPPORTED'])],
            'gpp' => ['required', Rule::in(['UNKNOWN', 'SUPPORTED', 'NOT_SUPPORTED'])],
            'gpc' => ['required', Rule::in(['UNKNOWN', 'SUPPORTED', 'NOT_SUPPORTED'])],
            'consent_before_request' => ['required', Rule::in(['UNKNOWN', 'REQUIRED', 'NOT_REQUIRED'])],
            'storage' => ['required', Rule::in(['UNKNOWN', 'REQUIRED', 'NOT_REQUIRED'])],
            'user_sync' => ['required', Rule::in(['UNKNOWN', 'REQUIRED', 'NOT_REQUIRED'])],
            'evidence_url' => ['nullable', 'url:https', 'max:1000'],
            'verified_at' => ['nullable', 'date', 'before_or_equal:today'],
        ]);
        $before = (array) $prebidBidder->privacy_capabilities;
        $prebidBidder->update(['privacy_capabilities' => $data]);
        $audit->record('prebid.bidder_privacy_capabilities.updated', $request->user()->organization_id, $request->user(), $prebidBidder, $before, $data);

        return back()->with('status', 'Bidder privacy capability evidence updated. UNKNOWN values remain explicit and are not inferred.');
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
            $decodedObject = json_decode($value, false, 512, JSON_THROW_ON_ERROR);
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages([$field => 'The JSON object is invalid.']);
        }
        if (! is_object($decodedObject) || ! is_array($decoded)) {
            throw ValidationException::withMessages([$field => 'The value must be a JSON object.']);
        }

        return $decoded;
    }
}
