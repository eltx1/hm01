<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StaticDeliveryPriority;
use App\Enums\StaticDeliveryStatus;
use App\Http\Controllers\Controller;
use App\Models\DemandNetwork;
use App\Models\DemandSite;
use App\Models\GamConnection;
use App\Models\LoaderRelease;
use App\Models\Placement;
use App\Models\PlatformControl;
use App\Models\ReportImportJob;
use App\Models\Site;
use App\Models\StaticDeliveryBatch;
use App\Models\StaticDeliveryItem;
use App\Models\SyntheticProbeResult;
use App\Models\SystemHeartbeat;
use App\Services\Audit\AuditRecorder;
use App\Services\Inventory\SiteConfigPublisher;
use App\Services\Operations\ExternalErrorSanitizer;
use App\Services\Operations\LoaderReleaseManager;
use App\Services\Operations\OperationsOverviewService;
use App\Services\Operations\PlatformControlService;
use App\Services\StaticDelivery\StaticDeliveryManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OperationsController extends Controller
{
    public function index(OperationsOverviewService $overview, ExternalErrorSanitizer $errors): View
    {
        $heartbeat = SystemHeartbeat::query()->find('scheduler');
        $staleAfter = (int) config('operations.heartbeat_stale_after_seconds', 180);
        $latestProbes = SyntheticProbeResult::withoutGlobalScopes()->latest('observed_at')->limit(100)->get()->unique('site_id')->values();
        $failedJobs = DB::table('failed_jobs')->latest('failed_at')->limit(50)->get()->map(function ($job) use ($errors) {
            $job->safe_exception = $errors->sanitize($job->exception ?? null, 700);

            return $job;
        });
        $failedImports = ReportImportJob::withoutGlobalScopes()
            ->with('connection:id,name')
            ->where('status', 'FAILED')
            ->latest()
            ->limit(50)
            ->get()
            ->each(fn (ReportImportJob $job) => $job->setAttribute('safe_error', $errors->sanitize($job->error_message, 700)));
        $deliveryBatches = StaticDeliveryBatch::query()->latest()->limit(25)->get()
            ->each(fn (StaticDeliveryBatch $batch) => $batch->setAttribute('safe_error', $errors->sanitize($batch->error_message, 700)));
        $globalControlRecords = PlatformControl::query()
            ->with('actor')
            ->where('scope_type', 'PLATFORM')
            ->where('scope_id', 'GLOBAL')
            ->whereIn('control_key', ['AD_SERVING', 'GAM', 'PREBID', 'DIRECT_JS', 'NATIVE_DEMAND'])
            ->get()
            ->keyBy('control_key');

        return view('admin.operations.index', [
            'overview' => $overview->snapshot(),
            'heartbeat' => $heartbeat,
            'heartbeatStale' => ! $heartbeat || $heartbeat->last_seen_at->lt(now()->subSeconds($staleAfter)),
            'failedJobs' => $failedJobs,
            'failedImports' => $failedImports,
            'controls' => PlatformControl::query()->with('actor')->orderByDesc('changed_at')->limit(250)->get(),
            'allowedControls' => (array) config('operations.controls', []),
            'globalEngineControls' => collect([
                'AD_SERVING' => 'All Ad Serving',
                'GAM' => 'GAM',
                'PREBID' => 'Prebid',
                'DIRECT_JS' => 'Direct JS',
            ])->map(function (string $label, string $key) use ($globalControlRecords): array {
                $record = $globalControlRecords->get($key);
                if ($key === 'DIRECT_JS' && ! $record?->is_disabled) {
                    $legacyRecord = $globalControlRecords->get('NATIVE_DEMAND');
                    $record = $legacyRecord?->is_disabled ? $legacyRecord : $record;
                }

                return [
                    'key' => $key,
                    'label' => $label,
                    'disabled' => (bool) $record?->is_disabled,
                    'reason' => $record?->reason,
                    'actor' => $record?->actor?->name,
                    'changed_at' => $record?->changed_at,
                ];
            })->values(),
            'sites' => Site::withoutGlobalScopes()->orderBy('display_name')->limit(1000)->get(['id', 'display_name']),
            'placements' => Placement::withoutGlobalScopes()->orderBy('name')->limit(2000)->get(['id', 'name', 'site_id']),
            'gamConnections' => GamConnection::withoutGlobalScopes()->orderBy('name')->limit(500)->get(['id', 'name', 'type', 'health_status', 'is_enabled']),
            'demandNetworks' => DemandNetwork::query()->orderBy('name')->get(['id', 'name', 'code', 'is_enabled']),
            'loaderReleases' => LoaderRelease::query()->latest('published_at')->get(),
            'deliveryBatches' => $deliveryBatches,
            'latestDelivery' => StaticDeliveryBatch::query()->where('status', StaticDeliveryStatus::Deployed->value)->latest('deployed_at')->first(),
            'pendingDeliveries' => StaticDeliveryItem::withoutGlobalScopes()->whereIn('status', [StaticDeliveryStatus::Pending->value, StaticDeliveryStatus::RetryScheduled->value])->count(),
            'failedDeliveries' => StaticDeliveryItem::withoutGlobalScopes()->where('status', StaticDeliveryStatus::Failed->value)->count(),
            'urgentDeliveries' => StaticDeliveryItem::withoutGlobalScopes()->where('priority', 'URGENT')->whereNotIn('status', [StaticDeliveryStatus::Deployed->value, StaticDeliveryStatus::Superseded->value])->count(),
            'deliveryBudgetUsed' => StaticDeliveryBatch::query()->where('created_at', '>=', now()->startOfMonth())->whereIn('status', [StaticDeliveryStatus::Uploading->value, StaticDeliveryStatus::Deployed->value])->count(),
            'deliveryBudget' => (int) config('static-delivery.monthly_deployment_budget', 450),
            'deliveryFileWarning' => (int) config('static-delivery.file_budget.warning_threshold', 18000),
            'deliveryFileLimit' => (int) config('static-delivery.file_budget.hard_limit', 20000),
            'latestProbes' => $latestProbes,
            'pilotReady' => $latestProbes->isNotEmpty()
                && $latestProbes->every(fn ($probe) => $probe->status === 'PASS' && $probe->observed_at->gte(now()->subMinutes(30)))
                && $heartbeat && ! $heartbeat->last_seen_at->lt(now()->subSeconds($staleAfter))
                && StaticDeliveryItem::withoutGlobalScopes()->where('status', StaticDeliveryStatus::Failed->value)->doesntExist(),
        ]);
    }

    public function control(Request $request, PlatformControlService $controls, SiteConfigPublisher $publisher): RedirectResponse
    {
        $data = $request->validate([
            'scope_type' => ['required', Rule::in(array_keys((array) config('operations.controls')))],
            'scope_id' => ['nullable', 'ulid'],
            'control_key' => ['required', 'string', 'max:48'],
            'is_disabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:8', 'max:2000'],
            'current_password' => ['required', 'string'],
            'impact_confirmation' => ['nullable', 'string', 'max:80'],
        ]);

        if (! Hash::check($data['current_password'], $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }

        $confirmation = $data['scope_type'] === 'PLATFORM' && (bool) $data['is_disabled']
            ? $this->platformDisableConfirmation($data['control_key'])
            : null;
        if ($confirmation && ($data['impact_confirmation'] ?? '') !== $confirmation) {
            throw ValidationException::withMessages([
                'impact_confirmation' => "Type {$confirmation} to confirm the platform-wide engine impact.",
            ]);
        }

        $control = $controls->set(
            $data['scope_type'],
            $data['scope_id'] ?? null,
            $data['control_key'],
            (bool) $data['is_disabled'],
            $data['reason'],
            $request->user(),
        );

        $changed = $control->wasRecentlyCreated || $control->wasChanged('is_disabled');
        if ($changed) {
            $urgent = $data['scope_type'] === 'PLATFORM'
                && (bool) $data['is_disabled']
                && in_array($data['control_key'], ['AD_SERVING', 'GAM', 'PREBID', 'DIRECT_JS', 'NATIVE_DEMAND'], true);
            $this->republishAffectedSites($data['scope_type'], $data['scope_id'] ?? null, $publisher, $request, $urgent);
        }

        return back()->with('status', $changed
            ? 'Operational control updated, audited, and queued for static edge delivery.'
            : 'No change was required; the control was already in that state.');
    }

    public function forgetFailedJob(Request $request, string $uuid, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
        ]);
        $job = DB::table('failed_jobs')->where('uuid', $uuid)->first();
        abort_unless($job, 404);

        Artisan::call('queue:forget', ['id' => $uuid]);
        $audit->record('operations.failed_job.forgotten', $request->user()->organization_id, $request->user(), metadata: [
            'uuid' => $uuid,
            'queue' => $job->queue,
            'reason' => $data['reason'],
        ]);

        return back()->with('status', 'Failed job removed and the action was audited.');
    }

    public function retryStaticDelivery(Request $request, StaticDeliveryBatch $staticDeliveryBatch, StaticDeliveryManager $manager, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
        ]);
        $manager->retry($staticDeliveryBatch);
        $audit->record('static.delivery.retry.requested', $request->user()->organization_id, $request->user(), $staticDeliveryBatch, newValues: [
            'batch_id' => $staticDeliveryBatch->id,
            'manifest_hash' => $staticDeliveryBatch->manifest_hash,
            'reason' => $data['reason'],
        ]);

        return back()->with('status', 'Static delivery retry scheduled and audited.');
    }

    private function republishAffectedSites(string $scopeType, ?string $scopeId, SiteConfigPublisher $publisher, Request $request, bool $urgent = false): void
    {
        $sites = match ($scopeType) {
            'SITE' => Site::withoutGlobalScopes()->whereKey($scopeId)->get(),
            'PLACEMENT' => ($siteId = Placement::withoutGlobalScopes()->whereKey($scopeId)->value('site_id'))
                ? Site::withoutGlobalScopes()->whereKey($siteId)->get()
                : collect(),
            'GAM_CONNECTION' => Site::withoutGlobalScopes()->where('gam_connection_id', $scopeId)->get(),
            'DEMAND_NETWORK' => Site::withoutGlobalScopes()->whereIn('id', DemandSite::withoutGlobalScopes()
                ->whereHas('account', fn ($query) => $query->where('demand_network_id', $scopeId))
                ->select('site_id'))->get(),
            'PLATFORM' => Site::withoutGlobalScopes()->get(),
            default => collect(),
        };

        foreach ($sites as $site) {
            $publisher->publishActiveProduction(
                $site,
                $request->user(),
                $urgent ? StaticDeliveryPriority::Urgent : StaticDeliveryPriority::Normal,
            );
        }
    }

    private function platformDisableConfirmation(string $control): ?string
    {
        return match ($control) {
            'AD_SERVING' => 'DISABLE PLATFORM AD SERVING',
            'GAM' => 'DISABLE PLATFORM GAM',
            'PREBID' => 'DISABLE PLATFORM PREBID',
            'DIRECT_JS', 'NATIVE_DEMAND' => 'DISABLE PLATFORM DIRECT JS',
            default => null,
        };
    }

    public function rollbackLoader(Request $request, LoaderReleaseManager $manager): RedirectResponse
    {
        $data = $request->validate([
            'loader_release_id' => ['required', 'exists:loader_releases,id'],
            'current_password' => ['required', 'current_password'],
        ]);
        $manager->activate(LoaderRelease::query()->findOrFail($data['loader_release_id']), $request->user());

        return back()->with('status', 'Loader release activated. Publish website configurations to roll sites to this release.');
    }
}
