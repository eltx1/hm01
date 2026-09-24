<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ConfigEnvironment;
use App\Http\Controllers\Controller;
use App\Models\Placement;
use App\Models\Site;
use App\Services\Demand\QuickAdManagementService;
use App\Services\Inventory\PlacementPresetCatalog;
use App\Services\Inventory\SiteConfigurationBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class QuickAdManagementController extends Controller
{
    public function index(Request $request, QuickAdManagementService $ads, PlacementPresetCatalog $presets, SiteConfigurationBuilder $builder): View
    {
        $data = $request->validate(['site' => ['nullable', 'ulid']]);
        $sites = Site::query()->with('publisher')->whereNotNull('publisher_id')->orderBy('display_name')->get();
        $site = filled($data['site'] ?? null) ? $sites->firstWhere('id', $data['site']) : null;
        abort_if(filled($data['site'] ?? null) && ! $site, 404);
        $placements = $site ? $ads->placements($site)->with('sizes')->orderBy('sort_order')->orderBy('name')->get() : collect();
        $config = $site ? $builder->build($site, ConfigEnvironment::Production, 0) : [];
        $production = $site?->configVersions()->where('environment', ConfigEnvironment::Production->value)
            ->with('deliveryItem')->latest('version')->first();

        return view('admin.demand.manage-ads', [
            'sites' => $sites, 'site' => $site, 'placements' => $placements,
            'presets' => $presets->quickChoices(), 'productionVersion' => $production,
            'runtimePlacements' => collect($config['placements'] ?? [])->keyBy('code'),
        ]);
    }

    public function update(Request $request, Site $site, Placement $placement, QuickAdManagementService $ads): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['pause', 'resume', 'remove', 'restore'])]]);
        $changed = $ads->change($site, $placement, $data['action'], $request->user());
        $message = match ($data['action']) {
            'pause' => 'Ad paused.', 'resume' => 'Ad resumed.',
            'remove' => 'Ad removed from this website. You can restore it below.',
            'restore' => 'Ad restored in paused state. Resume it when ready.',
        };

        return redirect()->route('admin.demand.quick.manage', ['site' => $site->id])
            ->with('status', $changed ? $message.($site->status->value === 'ACTIVE'
                ? ' The website update is queued for CDN delivery.' : ' Saved for the next website activation.') : 'This ad already has the requested state.');
    }
}
