<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Inventory\PlacementPresetBuilder;
use App\Services\Inventory\PlacementPresetCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SimplePlacementController extends Controller
{
    public function store(
        Request $request,
        Site $site,
        PlacementPresetBuilder $builder,
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

        $placement = $builder->create(
            $site,
            $data['placement_preset'],
            $request->user(),
            [
                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'ad_unit_id' => $data['ad_unit_id'] ?? null,
            ],
        );

        return back()->with('status', 'Placement '.$placement->code.' created with the '.$request->string('placement_preset')->replace('_', ' ')->title().' preset'.(
            $site->status->value === 'ACTIVE'
                ? ' and queued for production. It is ready to select in Quick Monetize.'
                : '. It will publish automatically when the website is activated.'
        ));
    }
}
