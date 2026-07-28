<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ConfigEnvironment;
use App\Http\Controllers\Controller;
use App\Models\BidderAccount;
use App\Models\GamConnection;
use App\Models\Placement;
use App\Models\PrebidAdapter;
use App\Models\PrebidBuild;
use App\Models\PrebidGamTemplate;
use App\Models\PrebidPriceBucket;
use App\Models\PrebidSetting;
use App\Models\PrebidSetupRun;
use App\Models\Site;
use App\Services\Gam\GamConnectionResolver;
use App\Services\Inventory\SiteConfigPublisher;
use App\Services\Prebid\PrebidConfigurationService;
use App\Services\Prebid\PrebidGamSetupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use JsonException;

class PrebidController extends Controller
{
    public function index(Request $request, Site $site, GamConnectionResolver $resolver, PrebidGamSetupService $setup): View
    {
        $site->load(['placements', 'publisher']);
        $resolved = $resolver->resolve($site);
        $connection = filled($request->query('gam_connection_id'))
            ? GamConnection::withoutGlobalScopes()->findOrFail($request->query('gam_connection_id'))
            : $resolved;
        $connectionId = $connection?->id;

        $accounts = BidderAccount::withoutGlobalScopes()
            ->with(['bidder.adapter', 'siteMappings' => fn ($query) => $query->where('site_id', $site->id), 'placementMappings.placement'])
            ->where('organization_id', $site->organization_id)
            ->orderBy('name')
            ->get();
        $settings = $connectionId ? PrebidSetting::withoutGlobalScopes()->with(['build', 'priceBucket'])->where('site_id', $site->id)->where('gam_connection_id', $connectionId)->first() : null;
        $template = $connectionId ? PrebidGamTemplate::withoutGlobalScopes()->with('priceBucket')->where('gam_connection_id', $connectionId)->first() : null;
        $runs = $connectionId ? PrebidSetupRun::withoutGlobalScopes()->where('gam_connection_id', $connectionId)->latest()->limit(20)->get() : collect();

        return view('admin.prebid.index', [
            'site' => $site,
            'resolvedConnection' => $resolved,
            'connection' => $connection,
            'connections' => GamConnection::withoutGlobalScopes()->where('is_enabled', true)->orderBy('name')->get(),
            'adapters' => PrebidAdapter::query()->orderBy('adapter_name')->get(),
            'builds' => PrebidBuild::query()->where('status', 'READY')->orderByDesc('is_active')->orderByDesc('built_at')->get(),
            'buckets' => PrebidPriceBucket::withoutGlobalScopes()->where('organization_id', $connection?->organization_id ?? $site->organization_id)->where('enabled', true)->orderByDesc('is_default')->get(),
            'accounts' => $accounts,
            'settings' => $settings,
            'template' => $template,
            'setupStatus' => $template ? $setup->incomplete($template) : null,
            'runs' => $runs,
            'bulkRuns' => PrebidSetupRun::withoutGlobalScopes()->where('status', 'PREVIEW')->latest()->get()->unique('gam_connection_id')->values(),
        ]);
    }

    public function storeAccount(Request $request, Site $site, PrebidConfigurationService $prebid): RedirectResponse
    {
        $data = $request->validate([
            'prebid_adapter_id' => ['required', 'ulid', 'exists:prebid_adapters,id'],
            'name' => ['required', 'string', 'max:255'],
            'publisher_id' => ['nullable', 'string', 'max:255'],
            'public_parameters_json' => ['nullable', 'string', 'max:20000'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        $data['public_parameters'] = $this->json($data['public_parameters_json'] ?? null, 'public_parameters_json');
        $data['enabled'] = $request->boolean('enabled', true);
        $account = $prebid->createAccount($site, $data, $request->user());

        return back()->with('status', "{$account->name} bidder account created. Assign it to this website or a placement next.");
    }

    public function assignSite(Request $request, Site $site, BidderAccount $bidderAccount, PrebidConfigurationService $prebid, SiteConfigPublisher $publisher): RedirectResponse
    {
        $data = $request->validate([
            'gam_connection_id' => ['nullable', 'ulid', 'exists:gam_connections,id'],
            'sequence' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'public_parameters_json' => ['nullable', 'string', 'max:20000'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        $connection = filled($data['gam_connection_id'] ?? null) ? GamConnection::withoutGlobalScopes()->findOrFail($data['gam_connection_id']) : null;
        $prebid->assignToSite($bidderAccount, $site, $connection, [
            'sequence' => $data['sequence'] ?? 100,
            'public_parameters' => $this->json($data['public_parameters_json'] ?? null, 'public_parameters_json'),
            'enabled' => $request->boolean('enabled', true),
        ], $request->user());
        $publisher->publish($site->refresh(), ConfigEnvironment::Production, $request->user());

        return back()->with('status', 'Bidder assignment published. The publisher installation code did not change.');
    }

    public function assignPlacement(Request $request, Site $site, Placement $placement, BidderAccount $bidderAccount, PrebidConfigurationService $prebid, SiteConfigPublisher $publisher): RedirectResponse
    {
        abort_unless($placement->site_id === $site->id, 404);
        $data = $request->validate([
            'gam_connection_id' => ['nullable', 'ulid', 'exists:gam_connections,id'],
            'public_parameters_json' => ['nullable', 'string', 'max:20000'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        $connection = filled($data['gam_connection_id'] ?? null) ? GamConnection::withoutGlobalScopes()->findOrFail($data['gam_connection_id']) : null;
        $prebid->assignToPlacement($bidderAccount, $placement, $connection, [
            'public_parameters' => $this->json($data['public_parameters_json'] ?? null, 'public_parameters_json'),
            'enabled' => $request->boolean('enabled', true),
        ], $request->user());
        $publisher->publish($site->refresh(), ConfigEnvironment::Production, $request->user());

        return back()->with('status', 'Placement bidder mapping published without changing publisher code.');
    }

    public function toggleAccount(Request $request, Site $site, BidderAccount $bidderAccount, PrebidConfigurationService $prebid, SiteConfigPublisher $publisher): RedirectResponse
    {
        abort_unless($bidderAccount->organization_id === $site->organization_id, 404);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $prebid->setAccountEnabled($bidderAccount, (bool) $data['enabled'], $request->user());
        $publisher->publish($site->refresh(), ConfigEnvironment::Production, $request->user());

        return back()->with('status', 'Bidder status changed and a new browser configuration was published.');
    }

    public function settings(Request $request, Site $site, GamConnection $gamConnection, PrebidConfigurationService $prebid, SiteConfigPublisher $publisher): RedirectResponse
    {
        $data = $request->validate([
            'prebid_build_id' => ['nullable', 'ulid', 'exists:prebid_builds,id'],
            'prebid_price_bucket_id' => ['nullable', 'ulid', 'exists:prebid_price_buckets,id'],
            'enabled' => ['sometimes', 'boolean'],
            'auction_timeout_ms' => ['required', 'integer', 'min:300', 'max:5000'],
            'price_granularity' => ['required', Rule::in(['low', 'medium', 'high', 'auto', 'dense', 'custom'])],
            'currency_code' => ['required', 'string', 'size:3'],
            'bidder_sequence' => ['required', Rule::in(['random', 'fixed'])],
            'consent_behavior_json' => ['nullable', 'string', 'max:20000'],
            'lazy_loading_json' => ['nullable', 'string', 'max:10000'],
            'refresh_behavior_json' => ['nullable', 'string', 'max:10000'],
            'timeout_reporting' => ['sometimes', 'boolean'],
            'gam_fallback' => ['sometimes', 'boolean'],
        ]);
        $data['enabled'] = $request->boolean('enabled');
        $data['timeout_reporting'] = $request->boolean('timeout_reporting');
        $data['gam_fallback'] = $request->boolean('gam_fallback', true);
        $data['consent_behavior'] = $this->json($data['consent_behavior_json'] ?? null, 'consent_behavior_json', null);
        $data['lazy_loading'] = $this->json($data['lazy_loading_json'] ?? null, 'lazy_loading_json', null);
        $data['refresh_behavior'] = $this->json($data['refresh_behavior_json'] ?? null, 'refresh_behavior_json', null);
        $prebid->saveSettings($site, $gamConnection, $data, $request->user());
        $version = $publisher->publish($site->refresh(), ConfigEnvironment::Production, $request->user());

        return back()->with('status', 'Prebid settings saved and production configuration v'.$version->version.' published.');
    }

    public function priceBucket(Request $request, Site $site, GamConnection $gamConnection, PrebidConfigurationService $prebid): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:80'],
            'currency_code' => ['required', 'string', 'size:3'],
            'ranges_json' => ['required', 'string', 'max:20000'],
            'is_default' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        $data['ranges'] = $this->json($data['ranges_json'], 'ranges_json');
        $data['is_default'] = $request->boolean('is_default');
        $data['enabled'] = $request->boolean('enabled', true);
        $bucket = $prebid->savePriceBucket($gamConnection, $data, $request->user());

        return back()->with('status', "Price bucket {$bucket->name} saved for {$gamConnection->name}. Run a new GAM dry-run before changing production granularity.");
    }

    public function previewSetup(Request $request, Site $site, GamConnection $gamConnection, PrebidGamSetupService $setup): RedirectResponse
    {
        $template = $setup->ensureTemplate($gamConnection, $request->user());
        $run = $setup->preview($template, $request->user());

        return back()->with('status', "Dry-run complete: {$run->estimated_objects} GAM objects remain. No external write was made.");
    }

    public function bulkPreviewSetup(Request $request, PrebidGamSetupService $setup): RedirectResponse
    {
        $data = $request->validate(['gam_connection_ids' => ['required', 'array', 'min:1'], 'gam_connection_ids.*' => ['ulid', 'exists:gam_connections,id']]);
        $templates = GamConnection::withoutGlobalScopes()->whereIn('id', $data['gam_connection_ids'])->get()->map(fn (GamConnection $connection) => $setup->ensureTemplate($connection, $request->user()))->all();
        $runs = $setup->previewBulk($templates, $request->user());
        $estimated = collect($runs)->sum('estimated_objects');

        return back()->with('status', count($runs)." GAM networks previewed; {$estimated} total objects remain. No external writes were made.");
    }

    public function bulkExecuteSetup(Request $request, PrebidGamSetupService $setup): RedirectResponse
    {
        $data = $request->validate([
            'prebid_setup_run_ids' => ['required', 'array', 'min:1'],
            'prebid_setup_run_ids.*' => ['ulid', 'exists:prebid_setup_runs,id'],
            'confirmation' => ['required', 'string'],
            'batch_size' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        $runs = PrebidSetupRun::withoutGlobalScopes()->whereIn('id', $data['prebid_setup_run_ids'])->get()->all();
        $confirmed = hash_equals((string) config('prebid.confirmation_phrase'), trim($data['confirmation']));
        $completed = $setup->executeBulk($runs, $request->user(), $confirmed, (int) ($data['batch_size'] ?? 100));
        $failed = collect($completed)->where('status', 'FAILED')->count();

        return back()->with($failed ? 'error' : 'status', count($completed)." GAM setup runs processed; {$failed} failed and can be resumed safely.");
    }

    public function executeSetup(Request $request, Site $site, PrebidSetupRun $prebidSetupRun, PrebidGamSetupService $setup): RedirectResponse
    {
        $data = $request->validate([
            'confirmation' => ['required', 'string'],
            'batch_size' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        abort_unless($prebidSetupRun->gam_connection_id === $prebidSetupRun->connection()->withoutGlobalScopes()->value('id'), 404);
        $confirmed = hash_equals((string) config('prebid.confirmation_phrase'), trim($data['confirmation']));
        $run = $setup->execute($prebidSetupRun, $request->user(), $confirmed, (int) ($data['batch_size'] ?? 100));

        return back()->with($run->status === 'FAILED' ? 'error' : 'status', $run->status === 'SUCCEEDED'
            ? 'Centralized Prebid GAM setup completed without duplicate objects.'
            : "Prebid GAM batch finished with status {$run->status}. Resume to continue safely.");
    }

    public function resumeSetup(Request $request, Site $site, PrebidSetupRun $prebidSetupRun, PrebidGamSetupService $setup): RedirectResponse
    {
        $data = $request->validate(['batch_size' => ['nullable', 'integer', 'min:1', 'max:500']]);
        $run = $setup->resume($prebidSetupRun, $request->user(), (int) ($data['batch_size'] ?? 100));

        return back()->with($run->status === 'FAILED' ? 'error' : 'status', "Prebid setup resumed; current status is {$run->status}.");
    }

    private function json(?string $value, string $field, mixed $default = []): mixed
    {
        if (blank($value)) {
            return $default;
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(422, "{$field} must contain valid JSON.");
        }
        if (! is_array($decoded)) {
            abort(422, "{$field} must contain a JSON object or array.");
        }

        return $decoded;
    }
}
