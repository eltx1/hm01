<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ConfigEnvironment;
use App\Http\Controllers\Controller;
use App\Models\ConfigVersion;
use App\Models\LoaderRelease;
use App\Models\Site;
use App\Models\SiteConfig;
use App\Models\TagVersion;
use App\Services\Audit\AuditRecorder;
use App\Services\Inventory\SiteConfigPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SiteConfigController extends Controller
{
    public function update(Request $request, Site $site, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate([
            'loader_release_id' => ['nullable', 'ulid', 'exists:loader_releases,id'],
            'tag_version_id' => ['nullable', 'ulid', 'exists:tag_versions,id'],
            'debug_enabled' => ['sometimes', 'boolean'],
            'house_ad_testing' => ['sometimes', 'boolean'],
            'single_request_mode' => ['sometimes', 'boolean'],
            'cache_ttl_seconds' => ['required', 'integer', 'between:0,86400'],
        ]);
        $config = SiteConfig::withoutGlobalScopes()->firstOrCreate(
            ['site_id' => $site->id],
            ['organization_id' => $site->organization_id],
        );
        $before = $config->toArray();
        $config->update([
            'loader_release_id' => $data['loader_release_id'] ?? $config->loader_release_id,
            'tag_version_id' => $data['tag_version_id'] ?? $config->tag_version_id,
            'debug_enabled' => $request->boolean('debug_enabled'),
            'house_ad_testing' => $request->boolean('house_ad_testing'),
            'single_request_mode' => $request->boolean('single_request_mode'),
            'cache_ttl_seconds' => $data['cache_ttl_seconds'],
        ]);
        $audit->record('site.config.settings.updated', $site->organization_id, $request->user(), $config, $before, $config->fresh()->toArray());

        return back()->with('status', 'Static configuration settings updated. Publish the required environment to deploy.');
    }

    public function preview(Request $request, Site $site, SiteConfigPublisher $publisher): JsonResponse
    {
        $data = $request->validate(['environment' => ['required', Rule::enum(ConfigEnvironment::class)]]);

        return response()->json($publisher->preview($site, ConfigEnvironment::from($data['environment'])), 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function publish(Request $request, Site $site, SiteConfigPublisher $publisher): RedirectResponse
    {
        $data = $request->validate(['environment' => ['required', Rule::enum(ConfigEnvironment::class)]]);
        $version = $publisher->publish($site, ConfigEnvironment::from($data['environment']), $request->user());

        return back()->with('status', $version->environment->value.' configuration v'.$version->version.' published to the static CDN directory.');
    }

    public function rollback(Request $request, Site $site, ConfigVersion $configVersion, SiteConfigPublisher $publisher): RedirectResponse
    {
        abort_unless($configVersion->site_id === $site->id, 404);
        $version = $publisher->rollback($site, $configVersion->environment, $configVersion, $request->user());

        return back()->with('status', $configVersion->environment->value.' rolled back through new version v'.$version->version.'.');
    }

    public function pause(Request $request, Site $site, SiteConfigPublisher $publisher): RedirectResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $version = $publisher->pauseImmediately($site, $request->user());

        return back()->with('status', 'Immediate pause published as production configuration v'.$version->version.'.');
    }

    public function resume(Request $request, Site $site, SiteConfigPublisher $publisher): RedirectResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $version = $publisher->resume($site, $request->user());

        return back()->with('status', 'Static ad delivery resumed through production configuration v'.$version->version.'.');
    }
}
