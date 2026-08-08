<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ConfigEnvironment;
use App\Enums\SiteStatus;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SiteConfigController extends Controller
{
    public function update(Request $request, Site $site, AuditRecorder $audit, SiteConfigPublisher $publisher): RedirectResponse
    {
        $data = $request->validate([
            'loader_release_id' => ['nullable', 'ulid', 'exists:loader_releases,id'],
            'tag_version_id' => ['nullable', 'ulid', 'exists:tag_versions,id'],
            'debug_enabled' => ['sometimes', 'boolean'],
            'house_ad_testing' => ['sometimes', 'boolean'],
            'single_request_mode' => ['sometimes', 'boolean'],
            'click_guard_enabled' => ['sometimes', 'boolean'],
            'click_guard_max_clicks' => ['sometimes', 'integer', 'between:1,50'],
            'click_guard_window_hours' => ['sometimes', 'integer', 'between:1,168'],
            'click_guard_block_hours' => ['sometimes', 'integer', 'between:1,720'],
            'cache_ttl_seconds' => ['required', 'integer', 'between:0,86400'],
            'privacy_settings_json' => ['nullable', 'string', 'max:30000'],
            'gpt_settings_json' => ['nullable', 'string', 'max:30000'],
            'supply_chain_settings_json' => ['nullable', 'string', 'max:30000'],
            'observability_settings_json' => ['nullable', 'string', 'max:30000'],
        ]);
        foreach (['privacy_settings', 'gpt_settings', 'supply_chain_settings', 'observability_settings'] as $field) {
            try {
                $data[$field] = filled($data[$field.'_json'] ?? null)
                    ? json_decode($data[$field.'_json'], true, 512, JSON_THROW_ON_ERROR) : [];
            } catch (\JsonException) {
                abort(422, str_replace('_', ' ', ucfirst($field)).' must be valid JSON.');
            }
            unset($data[$field.'_json']);
        }
        $version = DB::transaction(function () use ($site, $data, $request, $audit, $publisher) {
            $config = SiteConfig::withoutGlobalScopes()->firstOrCreate(
                ['site_id' => $site->id],
                ['organization_id' => $site->organization_id],
            );
            $before = $config->toArray();
            $existingClickGuard = $config->click_guard_settings ?? [];
            $config->update([
                'loader_release_id' => $data['loader_release_id'] ?? $config->loader_release_id,
                'tag_version_id' => $data['tag_version_id'] ?? $config->tag_version_id,
                'debug_enabled' => $request->boolean('debug_enabled'),
                'house_ad_testing' => $request->boolean('house_ad_testing'),
                'single_request_mode' => $request->boolean('single_request_mode'),
                'cache_ttl_seconds' => $data['cache_ttl_seconds'],
                'privacy_settings' => $data['privacy_settings'],
                'gpt_settings' => $data['gpt_settings'],
                'supply_chain_settings' => $data['supply_chain_settings'],
                'observability_settings' => $data['observability_settings'],
                'click_guard_settings' => [
                    'enabled' => $request->has('click_guard_enabled')
                        ? $request->boolean('click_guard_enabled')
                        : (bool) data_get($existingClickGuard, 'enabled', false),
                    'maxClicks' => (int) ($data['click_guard_max_clicks'] ?? data_get($existingClickGuard, 'maxClicks', 3)),
                    'windowHours' => (int) ($data['click_guard_window_hours'] ?? data_get($existingClickGuard, 'windowHours', 6)),
                    'blockHours' => (int) ($data['click_guard_block_hours'] ?? data_get($existingClickGuard, 'blockHours', 12)),
                ],
            ]);
            $audit->record('site.config.settings.updated', $site->organization_id, $request->user(), $config, $before, $config->fresh()->toArray());

            return $publisher->publishActiveProduction($site, $request->user());
        });

        return back()->with('status', $version
            ? 'Static settings updated and production configuration v'.$version->version.' was queued automatically.'
            : 'Static settings saved. Production will publish automatically when the website is activated.');
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

        return back()->with('status', $version->environment->value.' configuration v'.$version->version.' is pending batched static delivery.');
    }

    public function rollback(Request $request, Site $site, ConfigVersion $configVersion, SiteConfigPublisher $publisher): RedirectResponse
    {
        abort_unless($configVersion->site_id === $site->id, 404);
        $version = $publisher->rollback($site, $configVersion->environment, $configVersion, $request->user());

        return back()->with('status', $configVersion->environment->value.' rollback v'.$version->version.' is pending static delivery.');
    }

    public function pause(Request $request, Site $site, SiteConfigPublisher $publisher): RedirectResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $version = $publisher->pauseImmediately($site, $request->user());

        return back()->with('status', 'Emergency pause v'.$version->version.' was queued with urgent delivery priority.');
    }

    public function resume(Request $request, Site $site, SiteConfigPublisher $publisher): RedirectResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        abort_unless($site->status === SiteStatus::Active, 422, 'Activate the website before resuming its production configuration.');
        $version = $publisher->resume($site, $request->user());

        return back()->with('status', 'Resume configuration v'.$version->version.' is pending batched static delivery.');
    }
}
