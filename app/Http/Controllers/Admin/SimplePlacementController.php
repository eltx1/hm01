<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PlacementStatus;
use App\Http\Controllers\Controller;
use App\Models\Placement;
use App\Models\Site;
use App\Services\Inventory\InventoryManager;
use App\Services\Inventory\PlacementPresetCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class SimplePlacementController extends Controller
{
    public function store(
        Request $request,
        Site $site,
        InventoryManager $inventory,
        PlacementPresetCatalog $presets,
    ): RedirectResponse {
        $simplePresets = array_values(array_filter(
            $presets->keys(),
            fn (string $key): bool => $key !== PlacementPresetCatalog::CUSTOM,
        ));

        $data = $request->validate([
            'placement_preset' => ['required', Rule::in($simplePresets)],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:120'],
            'ad_unit_id' => ['nullable', 'ulid'],
        ]);

        $data = $presets->apply($data['placement_preset'], [
            'name' => $data['name'],
            'code' => $this->uniqueCode($site, $data['code'] ?? null, $data['name']),
            'ad_unit_id' => $data['ad_unit_id'] ?? null,
            'status' => PlacementStatus::Active->value,
            'targeting' => [],
            'lazy_fetch_margin_percent' => 500,
            'lazy_render_margin_percent' => 200,
            'lazy_mobile_scaling' => 2,
            'refresh_interval_seconds' => null,
            'refresh_limit' => null,
            'sort_order' => 0,
        ]);

        unset($data['placement_preset']);
        $placement = $inventory->createPlacement($site, $data, $request->user());

        return back()->with('status', 'Placement '.$placement->code.' created with the '.$request->string('placement_preset')->replace('_', ' ')->title().' preset'.(
            $site->status->value === 'ACTIVE'
                ? ' and queued for production. It is ready to select in Quick Monetize.'
                : '. It will publish automatically when the website is activated.'
        ));
    }

    private function uniqueCode(Site $site, ?string $requested, string $name): string
    {
        $base = Str::of($requested ?: $name)
            ->lower()
            ->replaceMatches('/[^a-z0-9_-]+/', '_')
            ->trim('_-')
            ->limit(100, '')
            ->value();
        $base = $base !== '' ? $base : 'placement';
        $candidate = $base;
        $suffix = 2;

        while (Placement::withoutGlobalScopes()->withTrashed()->where('site_id', $site->id)->where('code', $candidate)->exists()) {
            $candidate = Str::limit($base, 110, '').'_'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
