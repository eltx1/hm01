<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SiteStatus;
use App\Enums\StaticDeliveryPriority;
use App\Enums\TrafficGateAdminReadiness;
use App\Enums\TrafficGatePolicy;
use App\Enums\TrafficGateSitePolicy;
use App\Enums\TrafficGateSiteState;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Site;
use App\Services\Audit\AuditRecorder;
use App\Services\Inventory\SiteConfigPublisher;
use App\Services\Operations\PlatformControlService;
use App\Services\Settings\GlobalSettingsService;
use App\Services\TrafficGate\TrafficGateAdminOverviewService;
use App\Services\TrafficGate\TrafficGateConfigurationResolver;
use App\Services\TrafficGate\TrafficGateGlobalSettingsService;
use App\Services\TrafficGate\TrafficGateSiteSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class TrafficQualityController extends Controller
{
    private const CANDIDATE_SESSION_KEY = 'traffic_gate.sitekey_candidate';
    private const TEST_RESULT_SESSION_KEY = 'traffic_gate.sitekey_test_result';
    private const CLIENT_RESULTS = ['CLIENT PASS', 'CLIENT ERROR', 'CLIENT TIMEOUT', 'GATE UNREACHABLE'];

    public function index(
        Request $request,
        TrafficGateAdminOverviewService $overview,
        TrafficGateConfigurationResolver $resolver,
    ): View {
        $query = trim($request->string('q')->value());
        $sites = Site::withoutGlobalScopes()
            ->with(['publisher', 'servingSettings'])
            ->when($query !== '', function ($builder) use ($query): void {
                $builder->where(function ($inner) use ($query): void {
                    $inner->where('display_name', 'like', '%'.$query.'%')
                        ->orWhere('primary_domain', 'like', '%'.$query.'%')
                        ->orWhereHas('publisher', fn ($publisher) => $publisher->where('display_name', 'like', '%'.$query.'%'));
                });
            })
            ->orderBy('display_name')
            ->paginate(25)
            ->withQueryString();

        $staticStatuses = $overview->staticStatuses($sites->getCollection());
        $siteRows = $sites->getCollection()->map(function (Site $site) use ($resolver, $staticStatuses): array {
            $resolved = $resolver->resolve($site);

            return [
                'site' => $site,
                'effective_gate' => $resolved->enabled ? 'ENABLED' : 'DISABLED',
                'override' => $site->servingSettings?->traffic_gate_state?->value ?? TrafficGateSiteState::Inherit->value,
                'policy' => $resolved->policy->value,
                'policy_override' => $site->servingSettings?->traffic_gate_policy?->value ?? TrafficGateSitePolicy::Inherit->value,
                'static_status' => $staticStatuses[$site->id] ?? 'NOT PUBLISHED',
            ];
        });
        $sites->setCollection($siteRows);

        return view('admin.operations.traffic-quality', [
            'global' => $overview->globalSnapshot(),
            'siteCounts' => $overview->siteCounts(),
            'impact' => $overview->impactCounts(),
            'staticPublication' => $overview->staticSummary(),
            'sites' => $sites,
            'search' => $query,
            'candidateSitekey' => $request->session()->get(self::CANDIDATE_SESSION_KEY),
            'candidateTestResult' => $request->session()->get(self::TEST_RESULT_SESSION_KEY),
            'recentAudit' => $request->user()->hasPermission('audit.view')
                ? AuditLog::query()->where('event', 'like', 'traffic_gate.%')->latest()->limit(15)->get()
                : collect(),
        ]);
    }

    public function updateMaster(
        Request $request,
        TrafficGateAdminOverviewService $overview,
        TrafficGateGlobalSettingsService $settings,
    ): RedirectResponse {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:8', 'max:2000'],
            'current_password' => ['required', 'current_password'],
            'impact_confirmation' => ['required', 'string', 'max:80'],
        ]);
        $enabled = (bool) $data['enabled'];
        $confirmation = $enabled ? 'ENABLE CLIENT TRAFFIC GATE' : 'DISABLE CLIENT TRAFFIC GATE';
        $this->requireConfirmation($data['impact_confirmation'], $confirmation);

        if ($enabled && $overview->readiness() !== TrafficGateAdminReadiness::Ready) {
            throw ValidationException::withMessages([
                'enabled' => 'Client Traffic Gate cannot be enabled until configuration readiness is READY.',
            ]);
        }

        $settings->set($request->user(), 'traffic_gate.enabled', $enabled, $data['reason']);

        return back()->with('status', ($enabled ? 'Client Traffic Gate enabled' : 'Client Traffic Gate disabled').', audited, and queued through NORMAL Static Delivery.');
    }

    public function updatePolicy(Request $request, TrafficGateGlobalSettingsService $settings): RedirectResponse
    {
        $data = $request->validate([
            'policy' => ['required', Rule::enum(TrafficGatePolicy::class)],
            'reason' => ['required', 'string', 'min:8', 'max:2000'],
            'current_password' => ['required', 'current_password'],
            'impact_confirmation' => ['required', 'string', 'max:80'],
        ]);
        $policy = TrafficGatePolicy::from($data['policy']);
        $confirmation = $policy === TrafficGatePolicy::Strict ? 'SET STRICT TRAFFIC GATE' : 'CHANGE TRAFFIC GATE POLICY';
        $this->requireConfirmation($data['impact_confirmation'], $confirmation);

        $settings->set($request->user(), 'traffic_gate.policy', $policy->value, $data['reason']);

        return back()->with('status', "Client Traffic Gate policy changed to {$policy->value}, audited, and queued through NORMAL Static Delivery.");
    }

    public function updateAdvanced(
        Request $request,
        GlobalSettingsService $rawSettings,
        TrafficGateGlobalSettingsService $settings,
    ): RedirectResponse {
        $data = $request->validate([
            'initial_wait_ms' => ['required', 'integer', 'min:500', 'max:5000'],
            'max_wait_ms' => ['required', 'integer', 'min:2000', 'max:15000', 'gte:initial_wait_ms'],
            'retry_interval_ms' => ['required', 'integer', 'min:500', 'max:10000'],
            'activity_recovery_enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:8', 'max:2000'],
            'current_password' => ['required', 'current_password'],
            'impact_confirmation' => ['required', 'in:UPDATE TRAFFIC GATE TIMINGS'],
        ]);

        $currentInitial = (int) $rawSettings->get('traffic_gate.initial_wait_ms');
        $currentMaximum = (int) $rawSettings->get('traffic_gate.max_wait_ms');
        if ((int) $data['initial_wait_ms'] > $currentMaximum) {
            $settings->set($request->user(), 'traffic_gate.max_wait_ms', (int) $data['max_wait_ms'], $data['reason']);
            $settings->set($request->user(), 'traffic_gate.initial_wait_ms', (int) $data['initial_wait_ms'], $data['reason']);
        } elseif ((int) $data['max_wait_ms'] < $currentInitial) {
            $settings->set($request->user(), 'traffic_gate.initial_wait_ms', (int) $data['initial_wait_ms'], $data['reason']);
            $settings->set($request->user(), 'traffic_gate.max_wait_ms', (int) $data['max_wait_ms'], $data['reason']);
        } else {
            $settings->set($request->user(), 'traffic_gate.initial_wait_ms', (int) $data['initial_wait_ms'], $data['reason']);
            $settings->set($request->user(), 'traffic_gate.max_wait_ms', (int) $data['max_wait_ms'], $data['reason']);
        }
        $settings->set($request->user(), 'traffic_gate.retry_interval_ms', (int) $data['retry_interval_ms'], $data['reason']);
        $settings->set($request->user(), 'traffic_gate.activity_recovery_enabled', (bool) $data['activity_recovery_enabled'], $data['reason']);

        return back()->with('status', 'Advanced Client Traffic Gate settings updated, audited, and queued through NORMAL Static Delivery.');
    }

    public function stageSitekey(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'candidate_sitekey' => ['required', 'string', 'min:3', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        $request->session()->put(self::CANDIDATE_SESSION_KEY, $data['candidate_sitekey']);
        $request->session()->forget(self::TEST_RESULT_SESSION_KEY);

        return back()->with('status', 'Candidate public Sitekey staged. Run Client Test before activation.');
    }

    public function recordClientTest(Request $request, AuditRecorder $audit): JsonResponse
    {
        $data = $request->validate([
            'result' => ['required', Rule::in(self::CLIENT_RESULTS)],
        ]);
        $candidate = (string) $request->session()->get(self::CANDIDATE_SESSION_KEY, '');
        if ($candidate === '') {
            throw ValidationException::withMessages(['result' => 'No Sitekey candidate is staged.']);
        }

        $request->session()->put(self::TEST_RESULT_SESSION_KEY, $data['result']);
        $audit->record('traffic_gate.sitekey_candidate_tested', null, $request->user(), null,
            oldValues: [],
            newValues: ['result' => $data['result']],
            metadata: [
                'candidate_fingerprint' => substr(hash('sha256', $candidate), 0, 16),
                'client_only' => true,
                'token_validated' => false,
            ],
        );

        return response()->json(['result' => $data['result']]);
    }

    public function activateSitekey(
        Request $request,
        TrafficGateGlobalSettingsService $settings,
        GlobalSettingsService $rawSettings,
        AuditRecorder $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:2000'],
            'current_password' => ['required', 'current_password'],
            'impact_confirmation' => ['required', 'in:ACTIVATE TRAFFIC GATE SITEKEY'],
        ]);
        $candidate = (string) $request->session()->get(self::CANDIDATE_SESSION_KEY, '');
        $result = (string) $request->session()->get(self::TEST_RESULT_SESSION_KEY, '');
        if ($candidate === '' || $result !== 'CLIENT PASS') {
            throw ValidationException::withMessages([
                'candidate_sitekey' => 'Activation is blocked until the staged candidate completes a CLIENT PASS test.',
            ]);
        }

        $before = (string) ($rawSettings->get('traffic_gate.site_key') ?? '');
        $settings->set($request->user(), 'traffic_gate.site_key', $candidate, $data['reason']);
        $audit->record('traffic_gate.sitekey_activated', null, $request->user(), null,
            oldValues: ['fingerprint' => $before === '' ? null : substr(hash('sha256', $before), 0, 16)],
            newValues: ['fingerprint' => substr(hash('sha256', $candidate), 0, 16)],
            metadata: ['reason' => mb_substr($data['reason'], 0, 500), 'client_test_result' => $result, 'client_only' => true],
        );
        $request->session()->forget([self::CANDIDATE_SESSION_KEY, self::TEST_RESULT_SESSION_KEY]);

        return back()->with('status', 'Tested public Sitekey activated, audited, and queued through NORMAL Static Delivery.');
    }

    public function bulkInherit(Request $request, TrafficGateSiteSettingsService $settings): RedirectResponse
    {
        $data = $request->validate([
            'site_ids' => ['required', 'array', 'min:1', 'max:100'],
            'site_ids.*' => ['required', 'ulid', 'distinct'],
            'reason' => ['required', 'string', 'min:8', 'max:2000'],
            'current_password' => ['required', 'current_password'],
            'impact_confirmation' => ['required', 'in:RESET SELECTED SITES TO INHERIT'],
        ]);
        $sites = Site::withoutGlobalScopes()->whereIn('id', $data['site_ids'])->get();
        if ($sites->count() !== count($data['site_ids'])) {
            throw ValidationException::withMessages(['site_ids' => 'One or more selected Sites no longer exist.']);
        }

        foreach ($sites as $site) {
            $settings->update(
                $site,
                TrafficGateSiteState::Inherit,
                TrafficGateSitePolicy::Inherit,
                $request->user(),
                $data['reason'],
            );
        }

        return back()->with('status', $sites->count().' selected Site(s) reset to INHERIT through NORMAL Static Delivery.');
    }

    public function emergencyDisable(
        Request $request,
        PlatformControlService $controls,
        SiteConfigPublisher $publisher,
    ): RedirectResponse {
        abort_unless($request->user()->hasPermission('traffic_gate.emergency_disable'), 403);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:2000'],
            'current_password' => ['required', 'current_password'],
            'impact_confirmation' => ['required', 'in:EMERGENCY DISABLE TRAFFIC GATE'],
        ]);

        $control = $controls->set('PLATFORM', null, 'TRAFFIC_GATE', true, $data['reason'], $request->user());
        if ($control->wasRecentlyCreated || $control->wasChanged('is_disabled')) {
            $this->republishAllActive($request, $publisher, StaticDeliveryPriority::Urgent);
        }

        return back()->with('status', 'Emergency Disable Traffic Gate applied and queued through URGENT Static Delivery.');
    }

    public function clearEmergency(
        Request $request,
        PlatformControlService $controls,
        SiteConfigPublisher $publisher,
    ): RedirectResponse {
        abort_unless($request->user()->hasPermission('traffic_gate.emergency_disable'), 403);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:2000'],
            'current_password' => ['required', 'current_password'],
            'impact_confirmation' => ['required', 'in:CLEAR TRAFFIC GATE EMERGENCY'],
        ]);

        $control = $controls->set('PLATFORM', null, 'TRAFFIC_GATE', false, $data['reason'], $request->user());
        if ($control->wasChanged('is_disabled')) {
            $this->republishAllActive($request, $publisher, StaticDeliveryPriority::Normal);
        }

        return back()->with('status', 'Traffic Gate emergency disable cleared and queued through NORMAL Static Delivery.');
    }

    private function requireConfirmation(string $actual, string $expected): void
    {
        if ($actual !== $expected) {
            throw ValidationException::withMessages([
                'impact_confirmation' => "Type {$expected} to confirm this change.",
            ]);
        }
    }

    private function republishAllActive(Request $request, SiteConfigPublisher $publisher, StaticDeliveryPriority $priority): void
    {
        Site::withoutGlobalScopes()
            ->where('status', SiteStatus::Active->value)
            ->orderBy('id')
            ->each(fn (Site $site) => $publisher->publishActiveProduction($site, $request->user(), $priority));
    }
}
