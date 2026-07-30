<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ConfigEnvironment;
use App\Enums\ReportImportStatus;
use App\Http\Controllers\Controller;
use App\Models\ConfigVersion;
use App\Models\CronHeartbeat;
use App\Models\GamConnection;
use App\Models\LoaderRelease;
use App\Models\Placement;
use App\Models\ReportImportJob;
use App\Models\Site;
use App\Models\SiteConfig;
use App\Services\Audit\AuditRecorder;
use App\Services\Inventory\SiteConfigPublisher;
use App\Services\Operations\PlatformControlService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class OperationsController extends Controller
{
    public function index(PlatformControlService $controls): View
    {
        return view('admin.operations.index', [
            'controls' => $controls->all(),
            'heartbeats' => CronHeartbeat::query()->orderBy('name')->get(),
            'failedJobs' => DB::table('failed_jobs')->latest('failed_at')->limit(50)->get(),
            'failedImports' => ReportImportJob::withoutGlobalScopes()->with('connection.source')
                ->where('status', ReportImportStatus::Failed->value)->latest()->limit(50)->get(),
            'sites' => Site::withoutGlobalScopes()->with('siteConfig')->orderBy('display_name')->limit(100)->get(),
            'placements' => Placement::withoutGlobalScopes()->with('site')->latest()->limit(100)->get(),
            'gamConnections' => GamConnection::withoutGlobalScopes()->orderBy('name')->get(),
            'loaderReleases' => LoaderRelease::query()->latest('published_at')->get(),
            'configVersions' => ConfigVersion::withoutGlobalScopes()->with('site')->where('environment', ConfigEnvironment::Production->value)->latest('published_at')->limit(100)->get(),
        ]);
    }

    public function control(Request $request, PlatformControlService $controls): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', Rule::in(array_keys(PlatformControlService::DEFAULTS))],
            'enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:2000'],
            'current_password' => ['required', 'current_password'],
        ]);
        $controls->set($data['key'], (bool) $data['enabled'], $request->user(), $data['reason']);
        return back()->with('status', 'Platform control updated and browser control file published where applicable.');
    }

    public function site(Request $request, Site $site, PlatformControlService $controls, SiteConfigPublisher $publisher): RedirectResponse
    {
        $data = $this->toggleData($request);
        $controls->setSite($site, (bool) $data['enabled'], $request->user(), $publisher, $data['reason']);
        return back()->with('status', 'Website delivery control updated and production configuration published.');
    }

    public function placement(Request $request, Placement $placement, PlatformControlService $controls, SiteConfigPublisher $publisher): RedirectResponse
    {
        $data = $this->toggleData($request);
        $controls->setPlacement($placement, (bool) $data['enabled'], $request->user(), $publisher, $data['reason']);
        return back()->with('status', 'Placement delivery control updated and production configuration published.');
    }

    public function gamConnection(Request $request, GamConnection $gamConnection, PlatformControlService $controls): RedirectResponse
    {
        $data = $this->toggleData($request);
        $controls->setGamConnection($gamConnection, (bool) $data['enabled'], $request->user(), $data['reason']);
        return back()->with('status', 'GAM connection control updated. HORUS_GAM remains the default architecture when enabled.');
    }

    public function retryFailedJob(Request $request, string $uuid, AuditRecorder $audit): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        $audit->record('operations.failed_job.retried', $request->user()->organization_id, $request->user(), metadata: ['uuid' => $uuid]);
        return back()->with('status', 'Failed job returned to the database queue.');
    }

    public function forgetFailedJob(Request $request, string $uuid, AuditRecorder $audit): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->delete() === 1, 404);
        $audit->record('operations.failed_job.forgotten', $request->user()->organization_id, $request->user(), metadata: ['uuid' => $uuid]);
        return back()->with('status', 'Failed job record deleted.');
    }

    public function rollbackLoader(Request $request, LoaderRelease $loaderRelease, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'reason' => ['required', 'string', 'max:2000'],
            'apply_to_all_sites' => ['sometimes', 'boolean'],
        ]);
        abort_unless(is_file(public_path($loaderRelease->source_path)) && is_file(public_path($loaderRelease->minified_path)), 422, 'Selected loader assets are unavailable.');
        DB::transaction(function () use ($loaderRelease, $request, $data, $audit): void {
            LoaderRelease::query()->update(['is_active' => false]);
            $loaderRelease->update(['is_active' => true, 'published_at' => now()]);
            if ($request->boolean('apply_to_all_sites')) {
                SiteConfig::withoutGlobalScopes()->update(['loader_release_id' => $loaderRelease->id]);
            }
            $audit->record('operations.loader.rolled_back', $request->user()->organization_id, $request->user(), $loaderRelease,
                newValues: ['version' => $loaderRelease->version, 'apply_to_all_sites' => $request->boolean('apply_to_all_sites'), 'reason' => $data['reason']]);
        });
        return back()->with('status', 'Loader release '.$loaderRelease->version.' activated.');
    }

    public function rollbackConfig(Request $request, ConfigVersion $configVersion, SiteConfigPublisher $publisher, AuditRecorder $audit): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password'], 'reason' => ['required', 'string', 'max:2000']]);
        $site = Site::withoutGlobalScopes()->findOrFail($configVersion->site_id);
        $new = $publisher->rollback($site, $configVersion->environment, $configVersion, $request->user());
        $audit->record('operations.config.rolled_back', $site->organization_id, $request->user(), $new, newValues: [
            'source_version' => $configVersion->version,
            'new_version' => $new->version,
            'reason' => $request->string('reason')->toString(),
        ]);
        return back()->with('status', 'Configuration rolled back through immutable production version '.$new->version.'.');
    }

    private function toggleData(Request $request): array
    {
        return $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:2000'],
            'current_password' => ['required', 'current_password'],
        ]);
    }
}
