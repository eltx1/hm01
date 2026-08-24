<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SiteStatus;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteConfig;
use App\Services\Inventory\ClickGuardGlobalSettingsService;
use App\Services\Inventory\RuntimePolicyResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class ClickProtectionController extends Controller
{
    public function index(RuntimePolicyResolver $policies): View
    {
        $overrides = SiteConfig::withoutGlobalScopes()->get(['click_guard_settings'])
            ->filter(fn (SiteConfig $config): bool => data_get($config->click_guard_settings, 'inheritGlobal') === false)
            ->count();

        return view('admin.click-protection.index', [
            'policy' => $policies->globalClickGuard(),
            'websiteCount' => Site::withoutGlobalScopes()->count(),
            'activeWebsiteCount' => Site::withoutGlobalScopes()->where('status', SiteStatus::Active->value)->count(),
            'overrideCount' => $overrides,
        ]);
    }

    public function update(Request $request, ClickGuardGlobalSettingsService $settings): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'max_clicks' => ['required', 'integer', 'between:1,50'],
            'window_hours' => ['required', 'integer', 'between:1,168'],
            'block_hours' => ['required', 'integer', 'between:1,720'],
            'reason' => ['required', 'string', 'max:500'],
            'current_password' => ['required', 'string', 'max:255'],
            'impact_confirmation' => ['required', 'string', 'max:64'],
        ]);

        if (! Hash::check($data['current_password'], (string) $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }
        if (! hash_equals('CHANGE CLICK PROTECTION', trim($data['impact_confirmation']))) {
            throw ValidationException::withMessages(['impact_confirmation' => 'Type CHANGE CLICK PROTECTION to confirm this global change.']);
        }

        $published = $settings->update($request->user(), [
            'click_guard.enabled' => $request->boolean('enabled'),
            'click_guard.max_clicks' => $data['max_clicks'],
            'click_guard.window_hours' => $data['window_hours'],
            'click_guard.block_hours' => $data['block_hours'],
        ], $data['reason']);

        return back()->with('status', 'Global Click Protection saved. '.$published.' active website configuration(s) were queued for production publication.');
    }
}
