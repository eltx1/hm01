<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TrafficGateSitePolicy;
use App\Enums\TrafficGateSiteState;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\TrafficGate\TrafficGateSiteSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class TrafficGateController extends Controller
{
    public function updateSite(Request $request, Site $site, TrafficGateSiteSettingsService $settings): RedirectResponse
    {
        $data = $request->validate([
            'traffic_gate_state' => ['required', Rule::enum(TrafficGateSiteState::class)],
            'traffic_gate_policy' => ['required', Rule::enum(TrafficGateSitePolicy::class)],
            'reason' => ['required', 'string', 'min:8', 'max:2000'],
        ]);

        $version = $settings->update(
            $site,
            TrafficGateSiteState::from($data['traffic_gate_state']),
            TrafficGateSitePolicy::from($data['traffic_gate_policy']),
            $request->user(),
            $data['reason'],
        );

        return back()->with('status', $version
            ? 'Client Traffic Gate site override updated, audited, and queued through normal Static Delivery.'
            : 'Client Traffic Gate site override saved. No active production publication was required.');
    }
}
