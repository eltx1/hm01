<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Inventory\ClickGuardGlobalSettingsService;
use App\Services\Settings\GlobalSettingsService;
use App\Services\Settings\TypedSettingsRegistry;
use App\Services\StaticDelivery\SupplyChainStaticPublisher;
use App\Services\TrafficGate\TrafficGateGlobalSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class SettingsController extends Controller
{
    private const SUPPLY_CHAIN_ARTIFACT_KEYS = [
        'supply_chain.manager_domain',
        'supply_chain.contact_email',
        'supply_chain.contact_address',
        'supply_chain.tag_id',
    ];

    public function __construct(
        private readonly GlobalSettingsService $settings,
        private readonly TypedSettingsRegistry $registry,
        private readonly ClickGuardGlobalSettingsService $clickGuardSettings,
        private readonly TrafficGateGlobalSettingsService $trafficGateSettings,
        private readonly SupplyChainStaticPublisher $supplyChainPublisher,
    ) {}

    public function index(): View
    {
        return view('admin.settings.index', [
            'settings' => $this->settings->describe(),
            'trafficGateOrigin' => (string) config('traffic_gate.origin'),
        ]);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        $definition = $this->registry->get($key);
        $data = $request->validate([
            'value' => ['nullable'],
            'reason' => [$definition->highImpact ? 'required' : 'nullable', 'string', 'max:500'],
            'current_password' => [$definition->highImpact ? 'required' : 'nullable', 'string', 'max:255'],
            'impact_confirmation' => [$definition->highImpact ? 'required' : 'nullable', 'string', 'max:128'],
        ]);
        $this->authorizeImpact($request, $definition->highImpact, $definition->key, $data);

        if (str_starts_with($key, 'click_guard.')) {
            $this->clickGuardSettings->set($request->user(), $key, $request->input('value'), $data['reason'] ?? null);
        } elseif (str_starts_with($key, 'traffic_gate.')) {
            $this->trafficGateSettings->set($request->user(), $key, $request->input('value'), $data['reason'] ?? null);
        } else {
            $this->settings->set($request->user(), $key, $request->input('value'), $data['reason'] ?? null);
        }
        $this->queueSupplyChainPublication($key, $request, 'SETTING_UPDATED');

        return back()->with('status', 'Setting updated.');
    }

    public function reset(Request $request, string $key): RedirectResponse
    {
        $definition = $this->registry->get($key);
        $data = $request->validate([
            'reason' => [$definition->highImpact ? 'required' : 'nullable', 'string', 'max:500'],
            'current_password' => [$definition->highImpact ? 'required' : 'nullable', 'string', 'max:255'],
            'impact_confirmation' => [$definition->highImpact ? 'required' : 'nullable', 'string', 'max:128'],
        ]);
        $this->authorizeImpact($request, $definition->highImpact, $definition->key, $data);

        if (str_starts_with($key, 'click_guard.')) {
            $this->clickGuardSettings->reset($request->user(), $key, $data['reason'] ?? null);
        } elseif (str_starts_with($key, 'traffic_gate.')) {
            $this->trafficGateSettings->reset($request->user(), $key, $data['reason'] ?? null);
        } else {
            $this->settings->reset($request->user(), $key, $data['reason'] ?? null);
        }
        $this->queueSupplyChainPublication($key, $request, 'SETTING_RESET');

        return back()->with('status', 'Setting reset to its configured fallback.');
    }

    private function queueSupplyChainPublication(string $key, Request $request, string $event): void
    {
        if (! in_array($key, self::SUPPLY_CHAIN_ARTIFACT_KEYS, true)) {
            return;
        }

        $this->supplyChainPublisher->queueUrgent([
            'event' => $event,
            'setting_key' => $key,
        ], $request->user());
    }

    private function authorizeImpact(Request $request, bool $highImpact, string $key, array $data): void
    {
        if (! $highImpact) {
            return;
        }
        if (! Hash::check((string) ($data['current_password'] ?? ''), (string) $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }
        $expected = 'CHANGE '.strtoupper(str_replace(['.', '_'], ' ', $key));
        if (! hash_equals($expected, trim((string) ($data['impact_confirmation'] ?? '')))) {
            throw ValidationException::withMessages(['impact_confirmation' => 'Type '.$expected.' to confirm this high-impact change.']);
        }
    }
}
