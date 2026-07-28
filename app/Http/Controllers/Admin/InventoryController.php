<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PlacementStatus;
use App\Enums\PlacementType;
use App\Http\Controllers\Controller;
use App\Models\AdUnit;
use App\Models\GamRemoteObject;
use App\Models\Placement;
use App\Models\Site;
use App\Services\Inventory\AdUnitSyncService;
use App\Services\Inventory\InventoryManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InventoryController extends Controller
{
    public function index(Site $site): View
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
            'otherSites' => Site::withoutGlobalScopes()->whereKeyNot($site->id)->orderBy('display_name')->get(),
        ]);
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
        $data = $this->placementData($request, $site);
        $inventory->createPlacement($site, $data, $request->user());

        return back()->with('status', 'Placement created. The permanent Horus Loader code did not change.');
    }

    public function updatePlacement(Request $request, Site $site, Placement $placement, InventoryManager $inventory): RedirectResponse
    {
        abort_unless($placement->site_id === $site->id, 404);
        $data = $this->placementData($request, $site);
        $inventory->updatePlacement($placement, $data, $request->user());

        return back()->with('status', 'Placement settings updated. Publish a static configuration to deploy them.');
    }

    public function pageTargeting(Request $request, Site $site, InventoryManager $inventory): RedirectResponse
    {
        $data = $request->validate(['targeting_text' => ['nullable', 'string', 'max:10000']]);
        $inventory->setPageTargeting($site, $this->parseTargeting($data['targeting_text'] ?? ''), $request->user());

        return back()->with('status', 'Page-level targeting updated.');
    }

    public function bulkPlacements(Request $request, Site $site, InventoryManager $inventory): RedirectResponse
    {
        $data = $request->validate(['bulk_text' => ['required', 'string', 'max:50000']]);
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($data['bulk_text'])) as $lineNumber => $line) {
            if (trim($line) === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 5) {
                abort(422, 'Bulk line '.($lineNumber + 1).' must be code|name|type|ad_unit_code|sizes.');
            }
            [$code, $name, $type, $adUnitCode, $sizes] = $parts;
            $adUnit = AdUnit::withoutGlobalScopes()->where('site_id', $site->id)->where('code', strtolower($adUnitCode))->firstOrFail();
            $rows[] = [
                'code' => $code,
                'name' => $name,
                'type' => PlacementType::from(strtoupper($type))->value,
                'status' => PlacementStatus::Active->value,
                'ad_unit_id' => $adUnit->id,
                'sizes' => $this->parseFixedSizes($sizes),
            ];
        }
        $inventory->bulkCreatePlacements($site, $rows, $request->user());

        return back()->with('status', count($rows).' placements created.');
    }

    public function duplicateLayout(Request $request, Site $site, InventoryManager $inventory): RedirectResponse
    {
        $data = $request->validate(['target_site_id' => ['required', 'ulid', 'exists:sites,id']]);
        $target = Site::withoutGlobalScopes()->findOrFail($data['target_site_id']);
        $inventory->duplicateLayout($site, $target, $request->user());

        return back()->with('status', 'Layout copied to '.$target->display_name.' without copying GAM remote IDs.');
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

    private function placementData(Request $request, Site $site): array
    {
        $data = $request->validate([
            'ad_unit_id' => ['nullable', 'ulid'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::enum(PlacementType::class)],
            'status' => ['required', Rule::enum(PlacementStatus::class)],
            'sizes_text' => ['required', 'string', 'max:5000'],
            'responsive_text' => ['nullable', 'string', 'max:10000'],
            'targeting_text' => ['nullable', 'string', 'max:10000'],
            'lazy_load_enabled' => ['sometimes', 'boolean'],
            'lazy_fetch_margin_percent' => ['required', 'integer', 'between:0,5000'],
            'lazy_render_margin_percent' => ['required', 'integer', 'between:0,5000'],
            'lazy_mobile_scaling' => ['required', 'numeric', 'between:0.1,10'],
            'refresh_enabled' => ['sometimes', 'boolean'],
            'refresh_interval_seconds' => ['nullable', 'integer', 'between:30,3600'],
            'refresh_limit' => ['nullable', 'integer', 'between:1,100'],
            'collapse_empty_div' => ['sometimes', 'boolean'],
            'safeframe_enabled' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'between:0,100000'],
        ]);
        $data['lazy_load_enabled'] = $request->boolean('lazy_load_enabled');
        $data['refresh_enabled'] = $request->boolean('refresh_enabled');
        $data['collapse_empty_div'] = $request->boolean('collapse_empty_div');
        $data['safeframe_enabled'] = $request->boolean('safeframe_enabled');
        $data['sizes'] = array_merge(
            $this->parseFixedSizes($data['sizes_text']),
            $this->parseResponsiveSizes($data['responsive_text'] ?? ''),
        );
        $data['targeting'] = $this->parseTargeting($data['targeting_text'] ?? '');
        unset($data['sizes_text'], $data['responsive_text'], $data['targeting_text']);

        return $data;
    }

    private function parseFixedSizes(string $text): array
    {
        $sizes = [];
        foreach (array_filter(array_map('trim', explode(',', $text))) as $token) {
            if (strtolower($token) === 'fluid') {
                $sizes[] = ['size_type' => 'FLUID', 'device' => 'ALL'];
                continue;
            }
            if (! preg_match('/^(\d{1,4})x(\d{1,4})$/', strtolower($token), $match)) {
                abort(422, 'Invalid size '.$token.'. Use WIDTHxHEIGHT or fluid.');
            }
            $sizes[] = ['size_type' => 'FIXED', 'width' => (int) $match[1], 'height' => (int) $match[2], 'device' => 'ALL'];
        }

        return $sizes;
    }

    private function parseResponsiveSizes(string $text): array
    {
        $sizes = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($text)) as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (! preg_match('/^(\d+)x(\d+)\s*\|\s*(ALL|DESKTOP|TABLET|MOBILE)\s*\|\s*(.+)$/i', trim($line), $match)) {
                abort(422, 'Responsive size lines must be MINWIDTHxMINHEIGHT|DEVICE|300x250,728x90.');
            }
            foreach ($this->parseFixedSizes($match[4]) as $size) {
                $size['min_viewport_width'] = (int) $match[1];
                $size['min_viewport_height'] = (int) $match[2];
                $size['device'] = strtoupper($match[3]);
                $sizes[] = $size;
            }
        }

        return $sizes;
    }

    private function parseTargeting(string $text): array
    {
        $targeting = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($text)) as $line) {
            if (trim($line) === '') {
                continue;
            }
            [$key, $values] = array_pad(explode('=', $line, 2), 2, '');
            if (trim($key) === '') {
                abort(422, 'Targeting lines must use key=value1,value2.');
            }
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
