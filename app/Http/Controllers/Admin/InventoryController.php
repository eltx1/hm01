<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PlacementStatus;
use App\Enums\PlacementType;
use App\Http\Controllers\Controller;
use App\Models\AdUnit;
use App\Models\AdFormat;
use App\Models\GamRemoteObject;
use App\Models\LoaderRelease;
use App\Models\Placement;
use App\Models\Site;
use App\Models\SellerDeclaration;
use App\Models\TagVersion;
use App\Services\Compliance\SellerDeclarationManager;
use App\Services\Inventory\AdUnitSyncService;
use App\Services\Inventory\InventoryManager;
use App\Services\Inventory\RuntimePolicyResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InventoryController extends Controller
{
    public function index(Site $site, RuntimePolicyResolver $runtimePolicies): View
    {
        $site->load([
            'publisher', 'gamConnection', 'adUnits.sizes', 'placements.adUnit',
            'placements.sizes', 'placements.targeting', 'targeting', 'siteConfig',
            'configVersions' => fn ($query) => $query->latest('version')->limit(30),
        ]);
        $remoteMappings = GamRemoteObject::withoutGlobalScopes()
            ->whereIn('local_object_id', $site->adUnits->pluck('id'))
            ->where('local_object_type', 'ad_unit')
            ->get()
            ->keyBy('local_object_id');

        return view('admin.inventory.index', [
            'site' => $site,
            'remoteMappings' => $remoteMappings,
            'otherSites' => Site::withoutGlobalScopes()->where('id', '!=', $site->id)->orderBy('display_name')->get(),
            'loaderReleases' => LoaderRelease::query()->orderByDesc('published_at')->get(),
            'tagVersions' => TagVersion::query()->orderByDesc('published_at')->get(),
            'adFormats' => AdFormat::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'sellerDeclarations' => SellerDeclaration::withoutGlobalScopes()->where('site_id', $site->id)->orderBy('seller_id')->get(),
            'globalClickGuard' => $runtimePolicies->globalClickGuard(),
        ]);
    }

    public function storeSellerDeclaration(Request $request, Site $site, SellerDeclarationManager $sellers): RedirectResponse
    {
        $data = $request->validate([
            'seller_id' => ['required', 'string', 'max:255'],
            'seller_type' => ['required', Rule::in(['PUBLISHER', 'INTERMEDIARY', 'BOTH'])],
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['required', 'string', 'max:255', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
            'is_confidential' => ['sometimes', 'boolean'],
        ]);
        $data['is_confidential'] = $request->boolean('is_confidential');
        $declaration = $sellers->create($data + [
            'publisher_id' => $site->publisher_id,
            'site_id' => $site->id,
        ], $request->user());

        return redirect()->route('admin.compliance.sellers.show', $declaration)
            ->with('status', 'Seller declaration created disabled. Review it, then activate it from the Supply Chain Control Center.');
    }

    public function storeAdUnit(Request $request, Site $site, InventoryManager $inventory): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sizes_text' => ['required', 'string', 'max:5000'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);
        $data['is_enabled'] = $request->boolean('is_enabled');
        $data['sizes'] = $this->parseFixedSizes($data['sizes_text']);
        unset($data['sizes_text']);
        $inventory->createAdUnit($site, $data, $request->user());

        return back()->with('status', 'Local ad unit created and ready for GAM synchronization.');
    }

    public function storePlacement(Request $request, Site $site, InventoryManager $inventory): RedirectResponse
    {
        $inventory->createPlacement($site, $this->placementData($request), $request->user());

        return back()->with('status', $site->status->value === 'ACTIVE'
            ? 'Placement created and the production update was queued automatically. The permanent Horus Loader code did not change.'
            : 'Placement created. It will publish automatically on first activation.');
    }

    public function updatePlacement(Request $request, Site $site, Placement $placement, InventoryManager $inventory): RedirectResponse
    {
        abort_unless($placement->site_id === $site->id, 404);
        $inventory->updatePlacement($placement, $this->placementData($request), $request->user());

        return back()->with('status', $site->status->value === 'ACTIVE'
            ? 'Placement settings updated and the production update was queued automatically.'
            : 'Placement settings saved. They will publish automatically when the website is activated.');
    }

    public function pageTargeting(Request $request, Site $site, InventoryManager $inventory): RedirectResponse
    {
        $data = $request->validate(['targeting_text' => ['nullable', 'string', 'max:10000']]);
        $inventory->setPageTargeting($site, $this->parseTargeting($data['targeting_text'] ?? ''), $request->user());

        return back()->with('status', 'Page-level targeting updated'.($site->status->value === 'ACTIVE' ? ' and queued for production.' : ' for the next activation.'));
    }

    public function bulkPlacements(Request $request, Site $site, InventoryManager $inventory): RedirectResponse
    {
        $data = $request->validate(['bulk_text' => ['required', 'string', 'max:50000']]);
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($data['bulk_text'])) as $lineNumber => $line) {
            if (trim($line) === '') continue;
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 5) abort(422, 'Bulk line '.($lineNumber + 1).' must be code|name|type|ad_unit_code|sizes.');
            [$code, $name, $type, $adUnitCode, $sizes] = $parts;
            $adUnit = AdUnit::withoutGlobalScopes()->where('site_id', $site->id)->where('code', strtolower($adUnitCode))->firstOrFail();
            $rows[] = ['code' => $code, 'name' => $name, 'type' => PlacementType::from(strtoupper($type))->value, 'status' => PlacementStatus::Active->value, 'ad_unit_id' => $adUnit->id, 'sizes' => $this->parseFixedSizes($sizes)];
        }
        $inventory->bulkCreatePlacements($site, $rows, $request->user());

        return back()->with('status', count($rows).' placements created'.($site->status->value === 'ACTIVE' ? ' and queued as one production update.' : ' for the next activation.'));
    }

    public function duplicateLayout(Request $request, Site $site, InventoryManager $inventory): RedirectResponse
    {
        $data = $request->validate(['target_site_id' => ['required', 'ulid', 'exists:sites,id']]);
        $target = Site::withoutGlobalScopes()->findOrFail($data['target_site_id']);
        $inventory->duplicateLayout($site, $target, $request->user());

        return back()->with('status', 'Layout copied to '.$target->display_name.' without copying GAM remote IDs'.($target->status->value === 'ACTIVE' ? '; one production update was queued.' : '.'));
    }

    public function syncAdUnit(Request $request, Site $site, AdUnit $adUnit, AdUnitSyncService $sync): RedirectResponse
    {
        abort_unless($adUnit->site_id === $site->id, 404);
        $result = $sync->sync($adUnit, $request->user(), $request->boolean('dry_run', true), false);

        return back()->with($result->success ? 'status' : 'error', $this->syncMessage($result));
    }

    public function resyncAdUnit(Request $request, Site $site, AdUnit $adUnit, AdUnitSyncService $sync): RedirectResponse
    {
        abort_unless($adUnit->site_id === $site->id, 404);
        $result = $sync->sync($adUnit, $request->user(), $request->boolean('dry_run', true), true);

        return back()->with($result->success ? 'status' : 'error', $this->syncMessage($result));
    }

    private function placementData(Request $request): array
    {
        $data = $request->validate([
            'ad_unit_id' => ['nullable', 'ulid'], 'ad_format_id' => ['nullable', 'ulid', 'exists:ad_formats,id'], 'name' => ['required', 'string', 'max:255'], 'code' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::enum(PlacementType::class)], 'status' => ['required', Rule::enum(PlacementStatus::class)],
            'sizes_text' => ['nullable', 'string', 'max:5000'], 'responsive_text' => ['nullable', 'string', 'max:10000'], 'targeting_text' => ['nullable', 'string', 'max:10000'],
            'lazy_load_enabled' => ['sometimes', 'boolean'], 'lazy_fetch_margin_percent' => ['required', 'integer', 'between:0,5000'],
            'lazy_render_margin_percent' => ['required', 'integer', 'between:0,5000'], 'lazy_mobile_scaling' => ['required', 'numeric', 'between:0.1,10'],
            'refresh_enabled' => ['sometimes', 'boolean'], 'refresh_interval_seconds' => ['nullable', 'integer', 'between:30,3600'],
            'refresh_limit' => ['nullable', 'integer', 'between:1,100'], 'collapse_empty_div' => ['sometimes', 'boolean'],
            'safeframe_enabled' => ['sometimes', 'boolean'], 'sort_order' => ['nullable', 'integer', 'between:0,100000'], 'format_settings_json' => ['nullable', 'string', 'max:20000'],
        ]);
        $data['lazy_load_enabled'] = $request->boolean('lazy_load_enabled');
        $data['refresh_enabled'] = $request->boolean('refresh_enabled');
        $data['collapse_empty_div'] = $request->boolean('collapse_empty_div');
        $data['safeframe_enabled'] = $request->boolean('safeframe_enabled');
        $format = filled($data['ad_format_id'] ?? null) ? AdFormat::query()->findOrFail($data['ad_format_id']) : null;
        if ($format && $format->placement_type !== $data['type']) abort(422, 'The selected format is not compatible with the placement type.');
        $fixedSizes = $this->parseFixedSizes($data['sizes_text'] ?? '');
        if ($fixedSizes === [] && $format) {
            $fixedSizes = collect($format->default_sizes ?? [])->map(fn ($size) => $size === 'fluid'
                ? ['size_type' => 'FLUID', 'device' => 'ALL']
                : ['size_type' => 'FIXED', 'width' => (int) $size[0], 'height' => (int) $size[1], 'device' => 'ALL'])->all();
        }
        $data['sizes'] = array_merge($fixedSizes, $this->parseResponsiveSizes($data['responsive_text'] ?? ''));
        if ($data['sizes'] === [] && ! in_array($data['type'], [PlacementType::Interstitial->value, PlacementType::Native->value], true)) {
            abort(422, 'Select a format with default sizes or enter at least one size.');
        }
        $data['targeting'] = $this->parseTargeting($data['targeting_text'] ?? '');
        try {
            $data['format_settings'] = filled($data['format_settings_json'] ?? null)
                ? json_decode($data['format_settings_json'], true, 512, JSON_THROW_ON_ERROR) : [];
        } catch (\JsonException) {
            abort(422, 'Format settings must be valid JSON.');
        }
        unset($data['sizes_text'], $data['responsive_text'], $data['targeting_text'], $data['format_settings_json']);

        return $data;
    }

    private function parseFixedSizes(string $text): array
    {
        $sizes = [];
        foreach (array_filter(array_map('trim', explode(',', $text))) as $token) {
            if (strtolower($token) === 'fluid') { $sizes[] = ['size_type' => 'FLUID', 'device' => 'ALL']; continue; }
            if (! preg_match('/^(\d{1,4})x(\d{1,4})$/', strtolower($token), $match)) abort(422, 'Invalid size '.$token.'. Use WIDTHxHEIGHT or fluid.');
            $sizes[] = ['size_type' => 'FIXED', 'width' => (int) $match[1], 'height' => (int) $match[2], 'device' => 'ALL'];
        }

        return $sizes;
    }

    private function parseResponsiveSizes(string $text): array
    {
        $sizes = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($text)) as $line) {
            if (trim($line) === '') continue;
            if (! preg_match('/^(\d+)x(\d+)(?:-(\d+)x(\d+))?\s*\|\s*(ALL|DESKTOP|TABLET|MOBILE)\s*\|\s*(.+)$/i', trim($line), $match)) abort(422, 'Responsive lines must be MINxMIN[-MAXxMAX]|DEVICE|300x250,728x90.');
            foreach (array_filter(array_slice($match, 1, 4), fn ($value) => $value !== '') as $viewport) {
                if ((int) $viewport > 65535) abort(422, 'Responsive viewport dimensions cannot exceed 65535.');
            }
            if (filled($match[3] ?? null) && ((int) $match[3] < (int) $match[1] || (int) $match[4] < (int) $match[2])) {
                abort(422, 'Responsive maximum viewport must be greater than or equal to the minimum viewport.');
            }
            foreach ($this->parseFixedSizes($match[6]) as $size) {
                $size['min_viewport_width'] = (int) $match[1];
                $size['min_viewport_height'] = (int) $match[2];
                $size['max_viewport_width'] = filled($match[3] ?? null) ? (int) $match[3] : null;
                $size['max_viewport_height'] = filled($match[4] ?? null) ? (int) $match[4] : null;
                $size['device'] = strtoupper($match[5]);
                $sizes[] = $size;
            }
        }

        return $sizes;
    }

    private function parseTargeting(string $text): array
    {
        $targeting = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($text)) as $line) {
            if (trim($line) === '') continue;
            [$key, $values] = array_pad(explode('=', $line, 2), 2, '');
            if (trim($key) === '') abort(422, 'Targeting lines must use key=value1,value2.');
            $targeting[trim($key)] = array_values(array_filter(array_map('trim', explode(',', $values))));
        }

        return $targeting;
    }

    private function syncMessage($result): string
    {
        return match (true) {
            $result->dryRun => 'GAM synchronization dry-run completed without an external write.',
            $result->duplicate => 'The local and remote ad unit configurations already match.',
            $result->success => 'Ad unit synchronized and the GAM remote ID was stored.',
            default => 'GAM synchronization failed: '.$result->errorMessage,
        };
    }
}
