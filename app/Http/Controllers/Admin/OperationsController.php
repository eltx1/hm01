<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StaticDeliveryPriority;
use App\Http\Controllers\Controller;
use App\Models\GamConnection;
use App\Models\LoaderRelease;
use App\Models\Placement;
use App\Models\PlatformControl;
use App\Models\ReportImportJob;
use App\Models\Site;
use App\Models\SystemHeartbeat;
use App\Models\StaticDeliveryBatch;
use App\Models\StaticDeliveryItem;
use App\Enums\StaticDeliveryStatus;
use App\Services\Audit\AuditRecorder;
use App\Services\Operations\LoaderReleaseManager;
use App\Services\Operations\PlatformControlService;
use App\Services\Inventory\SiteConfigPublisher;
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
    public function index(): View
    {
        $heartbeat = SystemHeartbeat::query()->find('scheduler');
        $staleAfter = (int) config('operations.heartbeat_stale_after_seconds', 180);
        return view('admin.operations.index', [
            'heartbeat' => $heartbeat,
            'heartbeatStale' => ! $heartbeat || $heartbeat->last_seen_at->lt(now()->subSeconds($staleAfter)),
            'failedJobs' => DB::table('failed_jobs')->latest('failed_at')->limit(50)->get(),
            'failedImports' => ReportImportJob::withoutGlobalScopes()->where('status', 'FAILED')->latest()->limit(50)->get(),
            'controls' => PlatformControl::query()->with('actor')->orderBy('scope_type')->orderBy('control_key')->get(),
            'sites' => Site::withoutGlobalScopes()->orderBy('display_name')->get(['id', 'display_name']),
            'placements' => Placement::withoutGlobalScopes()->orderBy('name')->get(['id', 'name', 'site_id']),
            'gamConnections' => GamConnection::withoutGlobalScopes()->orderBy('name')->get(['id', 'name', 'type']),
            'loaderReleases' => LoaderRelease::query()->latest('published_at')->get(),
            'deliveryBatches' => StaticDeliveryBatch::query()->latest()->limit(25)->get(),
            'latestDelivery' => StaticDeliveryBatch::query()->where('status', StaticDeliveryStatus::Deployed->value)->latest('deployed_at')->first(),
            'pendingDeliveries' => StaticDeliveryItem::withoutGlobalScopes()->whereIn('status', [StaticDeliveryStatus::Pending->value, StaticDeliveryStatus::RetryScheduled->value])->count(),
            'failedDeliveries' => StaticDeliveryItem::withoutGlobalScopes()->where('status', StaticDeliveryStatus::Failed->value)->count(),
            'urgentDeliveries' => StaticDeliveryItem::withoutGlobalScopes()->where('priority', 'URGENT')->whereNotIn('status', [StaticDeliveryStatus::Deployed->value, StaticDeliveryStatus::Superseded->value])->count(),
            'deliveryBudgetUsed' => StaticDeliveryBatch::query()->where('created_at', '>=', now()->startOfMonth())->whereIn('status', [StaticDeliveryStatus::Uploading->value, StaticDeliveryStatus::Deployed->value])->count(),
            'deliveryBudget' => (int) config('static-delivery.monthly_deployment_budget', 450),
            'deliveryFileWarning' => (int) config('static-delivery.file_budget.warning_threshold', 18000),
            'deliveryFileLimit' => (int) config('static-delivery.file_budget.hard_limit', 20000),
        ]);
    }

    public function control(Request $request, PlatformControlService $controls, SiteConfigPublisher $publisher): RedirectResponse
    {
        $data = $request->validate([
            'scope_type' => ['required', Rule::in(array_keys((array) config('operations.controls')))],
            'scope_id' => ['nullable', 'ulid'], 'control_key' => ['required', 'string', 'max:48'],
            'is_disabled' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:8', 'max:2000'],
            'current_password' => ['required', 'string'],
        ]);
        if (! Hash::check($data['current_password'], $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }
        $controls->set($data['scope_type'], $data['scope_id'] ?? null, $data['control_key'], (bool) $data['is_disabled'], $data['reason'], $request->user());
        $urgent = (bool) $data['is_disabled'] && $data['control_key'] === 'AD_SERVING';
        $this->republishAffectedSites($data['scope_type'], $data['scope_id'] ?? null, $publisher, $request, $urgent);
        return back()->with('status', 'Operational control updated, audited, and queued for static edge delivery.');
    }

    public function retryFailedJob(Request $request, string $uuid): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        return back()->with('status', 'Failed job queued for retry.');
    }

    public function forgetFailedJob(Request $request, string $uuid): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        Artisan::call('queue:forget', ['id' => $uuid]);
        return back()->with('status', 'Failed job removed.');
    }

    public function retryStaticDelivery(Request $request, StaticDeliveryBatch $staticDeliveryBatch, StaticDeliveryManager $manager, AuditRecorder $audit): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        $manager->retry($staticDeliveryBatch);
        $audit->record('static.delivery.retry.requested', $request->user()->organization_id, $request->user(), $staticDeliveryBatch, newValues: [
            'batch_id' => $staticDeliveryBatch->id,
            'manifest_hash' => $staticDeliveryBatch->manifest_hash,
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

    public function rollbackLoader(Request $request, LoaderReleaseManager $manager): RedirectResponse
    {
        $data = $request->validate(['loader_release_id' => ['required', 'exists:loader_releases,id'], 'current_password' => ['required', 'current_password']]);
        $manager->activate(LoaderRelease::query()->findOrFail($data['loader_release_id']), $request->user());
        return back()->with('status', 'Loader release activated. Publish website configurations to roll sites to this release.');
    }
}
