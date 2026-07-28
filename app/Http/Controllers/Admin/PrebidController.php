<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PrebidBidderSequence;
use App\Enums\PrebidPriceGranularity;
use App\Http\Controllers\Controller;
use App\Models\BidderAccount;
use App\Models\BidderSiteMapping;
use App\Models\GamConnection;
use App\Models\Organization;
use App\Models\Placement;
use App\Models\PrebidAdapter;
use App\Models\PrebidBidder;
use App\Models\PrebidBuild;
use App\Models\PrebidGamTemplate;
use App\Models\PrebidSetupRun;
use App\Models\Site;
use App\Services\Prebid\PrebidGamAutomationService;
use App\Services\Prebid\PrebidGamTemplateFactory;
use App\Services\Prebid\PrebidSettingsManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use JsonException;

class PrebidController extends Controller
{
    public function index(PrebidGamTemplateFactory $templates): View
    {
        $connections = GamConnection::withoutGlobalScopes()->where('is_enabled', true)->orderByDesc('is_primary')->orderBy('name')->get();
        $connections->each(fn (GamConnection $connection) => $templates->ensureForConnection($connection));

        return view('admin.prebid.index', [
            'adapters' => PrebidAdapter::query()->withCount('bidders')->orderBy('display_name')->get(),
            'builds' => PrebidBuild::withoutGlobalScopes()->latest()->get(),
            'accounts' => BidderAccount::withoutGlobalScopes()->with('bidder.adapter', 'organization')->withCount('siteMappings')->latest()->get(),
            'connections' => $connections->load('sites'),
            'templates' => PrebidGamTemplate::withoutGlobalScopes()->with('connection')->get()->keyBy('gam_connection_id'),
            'runs' => PrebidSetupRun::withoutGlobalScopes()->with('connection', 'template', 'initiator')->latest()->limit(20)->get(),
            'sites' => Site::withoutGlobalScopes()->with('publisher')->orderBy('display_name')->get(),
        ]);
    }

    public function site(Site $site, PrebidSettingsManager $manager): View
    {
        $setting = $manager->ensureForSite($site)->load(['build', 'priceBuckets']);
        $site->load([
            'placements.sizes',
            'bidderSiteMappings.account.bidder.adapter',
            'bidderSiteMappings.placementMappings.placement',
            'configVersions' => fn ($query) => $query->latest()->limit(10),
        ]);

        return view('admin.prebid.site', [
            'site' => $site,
            'setting' => $setting,
            'builds' => PrebidBuild::withoutGlobalScopes()->where('is_active', true)->orderBy('name')->get(),
            'accounts' => BidderAccount::withoutGlobalScopes()->with('bidder.adapter', 'organization')->where('is_enabled', true)->orderBy('name')->get(),
        ]);
    }

    public function storeAccount(Request $request, PrebidSettingsManager $manager): RedirectResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'ulid', 'exists:organizations,id'],
            'prebid_bidder_id' => ['required', 'ulid', 'exists:prebid_bidders,id'],
            'name' => ['required', 'string', 'max:255'],
            'publisher_id' => ['nullable', 'string', 'max:255'],
            'account_code' => ['nullable', 'string', 'max:255'],
            'public_parameters_json' => ['nullable', 'string', 'max:20000'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);
        $bidder = PrebidBidder::withoutGlobalScopes()->findOrFail($data['prebid_bidder_id']);
        $data['public_parameters'] = $this->json($data['public_parameters_json'] ?? null, 'public_parameters_json');
        $data['is_enabled'] = $request->boolean('is_enabled', true);
        $manager->createAccount($bidder, $data, $request->user());

        return back()->with('status', 'Bidder account created. Only public browser parameters were stored.');
    }

    public function toggleAccount(Request $request, BidderAccount $bidderAccount, PrebidSettingsManager $manager): RedirectResponse
    {
        $data = $request->validate(['is_enabled' => ['required', 'boolean']]);
        $manager->setAccountEnabled($bidderAccount, (bool) $data['is_enabled'], $request->user());

        return back()->with('status', 'Bidder status updated. Publish affected site configurations to deploy the change without publisher code edits.');
    }

    public function updateSettings(Request $request, Site $site, PrebidSettingsManager $manager): RedirectResponse
    {
        $data = $request->validate([
            'prebid_build_id' => ['nullable', 'ulid', 'exists:prebid_builds,id'],
            'is_enabled' => ['sometimes', 'boolean'],
            'auction_timeout_ms' => ['required', 'integer', 'between:300,5000'],
            'price_granularity' => ['required', Rule::enum(PrebidPriceGranularity::class)],
            'currency' => ['required', 'string', 'size:3'],
            'bidder_sequence' => ['required', Rule::enum(PrebidBidderSequence::class)],
            'consent_config_json' => ['nullable', 'string', 'max:30000'],
            'user_sync_config_json' => ['nullable', 'string', 'max:30000'],
            'advanced_config_json' => ['nullable', 'string', 'max:30000'],
            'lazy_loading_enabled' => ['sometimes', 'boolean'],
            'refresh_enabled' => ['sometimes', 'boolean'],
            'refresh_interval_seconds' => ['nullable', 'integer', 'between:30,3600'],
            'timeout_reporting_enabled' => ['sometimes', 'boolean'],
            'gam_fallback_enabled' => ['sometimes', 'boolean'],
            'send_all_bids' => ['sometimes', 'boolean'],
            'debug_enabled' => ['sometimes', 'boolean'],
        ]);
        $data['is_enabled'] = $request->boolean('is_enabled');
        $data['lazy_loading_enabled'] = $request->boolean('lazy_loading_enabled');
        $data['refresh_enabled'] = $request->boolean('refresh_enabled');
        $data['timeout_reporting_enabled'] = $request->boolean('timeout_reporting_enabled');
        $data['gam_fallback_enabled'] = $request->boolean('gam_fallback_enabled');
        $data['send_all_bids'] = $request->boolean('send_all_bids');
        $data['debug_enabled'] = $request->boolean('debug_enabled');
        $data['consent_config'] = $this->json($data['consent_config_json'] ?? null, 'consent_config_json');
        $data['user_sync_config'] = $this->json($data['user_sync_config_json'] ?? null, 'user_sync_config_json');
        $data['advanced_config'] = $this->json($data['advanced_config_json'] ?? null, 'advanced_config_json');
        $manager->updateSettings($site, $data, $request->user());

        return back()->with('status', 'Prebid settings updated. Publish the static site configuration to deploy.');
    }

    public function assignSite(Request $request, Site $site, PrebidSettingsManager $manager): RedirectResponse
    {
        $data = $request->validate([
            'bidder_account_id' => ['required', 'ulid', 'exists:bidder_accounts,id'],
            'publisher_id' => ['nullable', 'string', 'max:255'],
            'public_parameters_json' => ['nullable', 'string', 'max:20000'],
            'sequence' => ['required', 'integer', 'between:1,1000'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);
        $account = BidderAccount::withoutGlobalScopes()->findOrFail($data['bidder_account_id']);
        $data['public_parameters'] = $this->json($data['public_parameters_json'] ?? null, 'public_parameters_json');
        $data['is_enabled'] = $request->boolean('is_enabled', true);
        $manager->assignAccountToSite($account, $site, $data, $request->user());

        return back()->with('status', 'Bidder assigned to the website.');
    }

    public function assignPlacement(Request $request, Site $site, BidderSiteMapping $bidderSiteMapping, PrebidSettingsManager $manager): RedirectResponse
    {
        abort_unless($bidderSiteMapping->site_id === $site->id, 404);
        $data = $request->validate([
            'placement_id' => ['required', 'ulid', Rule::exists('placements', 'id')->where('site_id', $site->id)],
            'placement_id_value' => ['nullable', 'string', 'max:255'],
            'public_parameters_json' => ['nullable', 'string', 'max:20000'],
            'sequence' => ['required', 'integer', 'between:1,1000'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);
        $placement = Placement::withoutGlobalScopes()->findOrFail($data['placement_id']);
        $data['public_parameters'] = $this->json($data['public_parameters_json'] ?? null, 'public_parameters_json');
        $data['is_enabled'] = $request->boolean('is_enabled', true);
        $manager->assignToPlacement($bidderSiteMapping, $placement, $data, $request->user());

        return back()->with('status', 'Bidder assigned to the selected placement.');
    }

    public function updateTemplate(Request $request, GamConnection $gamConnection, PrebidGamTemplateFactory $factory): RedirectResponse
    {
        $template = $factory->ensureForConnection($gamConnection);
        $data = $request->validate([
            'trafficker_id' => ['required', 'string', 'max:64'],
            'mode' => ['required', Rule::in(['TOP_PRICE', 'SEND_ALL_BIDS'])],
            'currency' => ['required', 'string', 'size:3'],
            'line_item_priority' => ['required', 'integer', 'between:1,16'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        $settings = array_merge($template->settings ?? [], ['trafficker_id' => $data['trafficker_id']]);
        $template->update([
            'mode' => $data['mode'],
            'currency' => strtoupper($data['currency']),
            'line_item_priority' => $data['line_item_priority'],
            'version' => $data['version'],
            'settings' => $settings,
        ]);

        return back()->with('status', 'Connection-specific Prebid GAM template updated.');
    }

    public function previewSetup(Request $request, GamConnection $gamConnection, PrebidGamAutomationService $automation): RedirectResponse
    {
        $data = $request->validate(['site_id' => ['nullable', 'ulid', 'exists:sites,id']]);
        $site = filled($data['site_id'] ?? null) ? Site::withoutGlobalScopes()->findOrFail($data['site_id']) : null;
        if ($site) {
            abort_unless(app(\App\Services\Gam\GamConnectionResolver::class)->resolve($site)?->id === $gamConnection->id, 422, 'The website does not currently resolve to this GAM connection.');
        }
        $preview = $automation->preview($gamConnection, $request->user(), $site);

        return redirect()->route('admin.prebid.setup-runs.show', $preview['run'])
            ->with('prebid_confirmation_token', $preview['confirmationToken'])
            ->with('status', 'Dry-run preview created. No Google Ad Manager write was made.');
    }

    public function showRun(PrebidSetupRun $prebidSetupRun): View
    {
        return view('admin.prebid.setup-run', [
            'run' => $prebidSetupRun->load('connection', 'template', 'site', 'remoteObjects', 'errors'),
            'confirmationToken' => session('prebid_confirmation_token'),
        ]);
    }

    public function executeSetup(Request $request, PrebidSetupRun $prebidSetupRun, PrebidGamAutomationService $automation): RedirectResponse
    {
        $data = $request->validate([
            'confirmation_token' => ['nullable', 'string', 'max:64'],
            'batch_limit' => ['required', 'integer', 'between:1,100'],
        ]);
        $run = $automation->executeBatch($prebidSetupRun, $request->user(), $data['confirmation_token'] ?? null, (int) $data['batch_limit']);

        return back()->with('status', $run->status->value === 'SUCCEEDED'
            ? 'Prebid GAM automation completed without duplicate objects.'
            : 'Batch processed. Resume the same run to continue from its saved cursor.');
    }

    private function json(?string $value, string $field): array
    {
        if (blank($value)) {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(422, "{$field} must contain valid JSON.");
        }

        abort_unless(is_array($decoded), 422, "{$field} must contain a JSON object.");

        return $decoded;
    }
}
