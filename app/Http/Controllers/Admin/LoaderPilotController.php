<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ConfigEnvironment;
use App\Http\Controllers\Controller;
use App\Models\ConfigVersion;
use App\Models\LoaderRelease;
use App\Models\Site;
use App\Services\Audit\AuditRecorder;
use App\Services\Inventory\LoaderArchiveRegistry;
use App\Services\Inventory\SiteConfigPublisher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LoaderPilotController extends Controller
{
    public function show(Site $site, LoaderArchiveRegistry $archives)
    {
        $records = $archives->records();
        $selection = $this->selection($site);
        $number = (int) ($site->siteConfig?->production_version ?? 0);
        $confirmed = ConfigVersion::query()->where('site_id', $site->id)->where('organization_id', $site->organization_id)
            ->where('environment', 'PRODUCTION')->where('version', $number)->first();
        $publishedBuild = data_get($confirmed?->payload, 'loader.runtimeBuild');
        $publishedBuild = is_string($publishedBuild) && preg_match('/^sha256:[a-f0-9]{64}$/D', $publishedBuild) ? $publishedBuild : null;

        return response()->view('admin.sites.loader-pilot', compact('site', 'records', 'selection', 'number', 'publishedBuild'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, Site $site, LoaderArchiveRegistry $archives, SiteConfigPublisher $publisher, AuditRecorder $audit)
    {
        $data = $request->validate(['release' => ['required', 'regex:/^(default|[a-f0-9]{64})$/D'],
            'expected_selection' => ['required', 'regex:/^(default|[a-f0-9]{64})$/D'], 'reason' => ['required', 'string', 'min:8', 'max:500']]);
        $version = DB::transaction(function () use ($site, $data, $archives, $publisher, $audit, $request) {
            $site = Site::withoutGlobalScopes()->whereKey($site->id)->lockForUpdate()->firstOrFail();
            $current = $this->selection($site);
            if ($current !== $data['expected_selection']) throw ValidationException::withMessages(['release' => 'The selection changed. Refresh this page before applying.']);
            if ($current === $data['release']) return null;
            $id = null;
            if ($data['release'] !== 'default') {
                $record = $archives->records()[$data['release']] ?? null;
                if (! $record) throw ValidationException::withMessages(['release' => 'The selected immutable archive is unavailable.']);
                $release = LoaderRelease::firstOrCreate(['version' => $record['version'].'+'.substr($data['release'], 0, 16)], [
                    'organization_id' => $request->user()->organization_id, 'source_path' => 'assets/loader/'.$record['file'],
                    'minified_path' => 'runtime/loader/'.$record['file'], 'checksum' => $data['release'], 'is_active' => false,
                    'notes' => 'Manual per-site immutable pilot. Never selected as the global default.', 'published_at' => now(),
                ]);
                if ($release->checksum !== $data['release'] || $release->minified_path !== 'runtime/loader/'.$record['file']) {
                    throw ValidationException::withMessages(['release' => 'The release identity conflicts with an existing record.']);
                }
                $id = $release->id;
            }
            $config = $site->siteConfig()->firstOrCreate([], ['organization_id' => $site->organization_id]);
            $config->update(['loader_release_id' => $id]);
            $audit->record('site.loader.pilot.changed', $site->organization_id, $request->user(), $site,
                ['selection' => $current], ['selection' => $data['release']], ['reason' => $data['reason']]);
            return $publisher->publishActiveProduction($site->fresh(), $request->user());
        });

        return back()->with('status', $version ? 'Loader selection saved; production version '.$version->version.' is queued for normal delivery.' : 'Loader selection is unchanged, or saved while the website is inactive. No new production version was queued.');
    }

    private function selection(Site $site): string
    {
        $release = $site->siteConfig?->loaderRelease;
        return $release && str_starts_with($release->minified_path, 'runtime/loader/') ? $release->checksum : 'default';
    }
}
