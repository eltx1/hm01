<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DemandNetworkCode;
use App\Enums\PlacementStatus;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Http\Controllers\Controller;
use App\Models\DemandNetwork;
use App\Models\Placement;
use App\Models\Site;
use App\Services\Demand\QuickMonetizeService;
use App\Services\Inventory\PlacementPresetBuilder;
use App\Services\Inventory\PlacementPresetCatalog;
use App\Services\Operations\PlatformControlService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use ReflectionMethod;

final class DirectDemandQuickMonetizeController extends Controller
{
    public function create(
        Request $request,
        PlatformControlService $controls,
        PlacementPresetCatalog $presets,
    ): View {
        $network = $this->network();
        $sites = Site::withoutGlobalScopes()
            ->with([
                'publisher',
                'placements' => fn ($query) => $query
                    ->withoutGlobalScopes()
                    ->whereNull('placements.deleted_at')
                    ->where('status', PlacementStatus::Active->value)
                    ->with(['sizes', 'adFormat'])
                    ->orderBy('sort_order')
                    ->orderBy('name'),
            ])
            ->whereNull('sites.deleted_at')
            ->where('status', SiteStatus::Active->value)
            ->whereNotNull('publisher_id')
            ->orderBy('display_name')
            ->get();

        return view('admin.demand.quick', [
            'sites' => $sites,
            'network' => $network,
            'blockingReasons' => $this->readinessProblems($controls, $network),
            'selectedSiteId' => (string) $request->query('site', ''),
            'quickPresets' => $presets->quickChoices(),
        ]);
    }

    public function store(
        Request $request,
        QuickMonetizeService $quick,
        PlacementPresetCatalog $presets,
        PlatformControlService $controls,
    ): RedirectResponse {
        $mode = strtolower((string) $request->input(
            'placement_mode',
            $request->filled('placement_id') ? 'existing' : 'new',
        ));
        $request->merge([
            'placement_mode' => $mode,
            'placement_preset' => $request->input('placement_preset', $mode === 'new' ? 'responsive_display' : null),
        ]);

        $quickPresetKeys = array_keys($presets->quickChoices());
        $data = $request->validate([
            'site_id' => ['required', 'ulid', 'exists:sites,id'],
            'placement_mode' => ['required', Rule::in(['new', 'existing'])],
            'placement_preset' => ['nullable', Rule::in($quickPresetKeys)],
            'placement_id' => ['nullable', 'ulid', 'exists:placements,id'],
            'placement_name' => ['nullable', 'string', 'max:255'],
            'tag' => ['required', 'string', 'max:60000'],
            'tag_input_type' => ['nullable', Rule::in(['AUTO', 'PROVIDER_TAG', 'GAM_AD_UNIT_PATH'])],
        ]);

        $network = $this->network();
        $problems = $this->readinessProblems($controls, $network);
        if ($problems !== []) {
            throw ValidationException::withMessages(['quick' => implode(' ', $problems)]);
        }
        if (! $network) {
            throw ValidationException::withMessages(['quick' => 'Custom Third-Party Tag connector is unavailable.']);
        }

        $site = Site::withoutGlobalScopes()
            ->with(['publisher', 'siteConfig'])
            ->whereNull('deleted_at')
            ->findOrFail($data['site_id']);

        if ($site->status !== SiteStatus::Active) {
            throw ValidationException::withMessages(['site_id' => 'Quick Monetize is available only for active websites.']);
        }
        if ($site->serving_mode === ServingMode::Paused
            || $site->siteConfig?->immediate_pause
            || ($site->siteConfig && $site->siteConfig->status !== 'ACTIVE')) {
            throw ValidationException::withMessages(['site_id' => 'This website is operationally paused. Resume it before publishing a new ad tag.']);
        }
        if (! $site->publisher) {
            throw ValidationException::withMessages(['site_id' => 'The selected website is not attached to a Publisher.']);
        }

        $placement = null;
        $preset = null;
        if ($data['placement_mode'] === 'existing') {
            if (empty($data['placement_id'])) {
                throw ValidationException::withMessages(['placement_id' => 'Choose an existing placement.']);
            }
            $placement = Placement::withoutGlobalScopes()
                ->with('sizes')
                ->whereNull('deleted_at')
                ->findOrFail($data['placement_id']);
            if ($placement->site_id !== $site->id) {
                throw ValidationException::withMessages(['placement_id' => 'The selected placement does not belong to this website.']);
            }
            if ($placement->status !== PlacementStatus::Active) {
                throw ValidationException::withMessages(['placement_id' => 'Quick Monetize requires an active placement.']);
            }
            $this->assertExistingPlacementQuickCompatible($placement);
        } else {
            $preset = (string) ($data['placement_preset'] ?? 'responsive_display');
            if (! array_key_exists($preset, $presets->quickChoices())) {
                throw ValidationException::withMessages([
                    'placement_preset' => 'Choose a Quick-compatible ad format / surface. Provider-managed formats are available in Advanced Inventory.',
                ]);
            }
        }

        $result = $quick->activate(
            $site,
            $network,
            $request->user(),
            (string) $data['tag'],
            $placement,
            $preset,
            $data['placement_name'] ?? null,
            (string) ($data['tag_input_type'] ?? 'AUTO'),
        );
        $placement = $result['placement'];
        $account = $result['account'];
        $savedName = count($result['placements'] ?? []) === PlacementPresetBuilder::RESPONSIVE_BUNDLE_SIZE
            ? 'Responsive Display · '.PlacementPresetBuilder::RESPONSIVE_BUNDLE_SIZE.' manual placements'
            : $placement->name;

        return redirect()
            ->route('admin.demand.quick.create', ['site' => $site->id])
            ->with('status', "{$savedName} was saved for {$site->primary_domain}. Production configuration is queued; the ad is not confirmed live until CDN delivery completes.")
            ->with('quick_account_id', $account->id)
            ->with('quick_placement_id', $placement->id);
    }

    /** @param array<int, array<string, mixed>> $scripts
     *  @return array<int, string>
     */
    private function scriptOrigins(array $scripts): array
    {
        $service = app(QuickMonetizeService::class);
        $method = new ReflectionMethod($service, 'scriptOrigins');
        $method->setAccessible(true);

        return $method->invoke($service, $scripts);
    }

    /**
     * @return array{all:array<int,string>,frame:array<int,string>,image:array<int,string>,style:array<int,string>,media:array<int,string>,font:array<int,string>}
     */
    private function resourceOrigins(string $tag): array
    {
        $service = app(QuickMonetizeService::class);
        $method = new ReflectionMethod($service, 'resourceOrigins');
        $method->setAccessible(true);

        return $method->invoke($service, $tag);
    }

    private function assertExistingPlacementQuickCompatible(Placement $placement): void
    {
        $active = $placement->sizes->where('is_active', true)->values();
        if ($active->contains(fn ($size) => $size->size_type === 'FLUID')) {
            throw ValidationException::withMessages([
                'tag' => 'Quick Monetize generic tags do not support fluid sizes on an arbitrary existing placement. Use a maintained Horus preset or Advanced setup with a provider adapter.',
            ]);
        }

        $fixed = $active
            ->filter(fn ($size) => $size->size_type === 'FIXED' && $size->width && $size->height)
            ->map(fn ($size): string => ((int) $size->width).'x'.((int) $size->height))
            ->unique();
        $presetManaged = (bool) data_get($placement->metadata, 'quick_monetize_generated', false)
            || filled(data_get($placement->metadata, 'placement_preset'));
        if ($fixed->count() > 1 && ! $presetManaged) {
            throw ValidationException::withMessages([
                'tag' => 'Quick Monetize multi-size delivery requires a maintained Horus placement preset with an explicit responsive policy. Use a preset or Advanced setup for an arbitrary multi-size placement.',
            ]);
        }
    }

    private function network(): ?DemandNetwork
    {
        return DemandNetwork::query()
            ->where('code', DemandNetworkCode::CustomThirdPartyTag->value)
            ->first();
    }

    /** @return array<int, string> */
    private function readinessProblems(PlatformControlService $controls, ?DemandNetwork $network): array
    {
        $problems = [];
        if ($controls->disabled('PLATFORM', null, 'DIRECT_JS')) {
            $problems[] = 'Direct Demand master is paused.';
        }
        if (! $network) {
            $problems[] = 'Custom Third-Party Tag connector is missing.';

            return $problems;
        }
        if (! $network->is_enabled) {
            $problems[] = 'Custom Third-Party Tag connector is disabled.';
        }
        if (! $network->supports_direct_js) {
            $problems[] = 'Custom Third-Party Tag direct delivery is disabled.';
        }
        foreach (['DIRECT_JS', 'AD_SERVING', 'NATIVE_DEMAND'] as $control) {
            if ($controls->disabled('DEMAND_NETWORK', $network->id, $control)) {
                $problems[] = "Connector runtime {$control} is paused.";
            }
        }

        return array_values(array_unique($problems));
    }
}
