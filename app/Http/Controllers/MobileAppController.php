<?php

namespace App\Http\Controllers;

use App\Models\{DailyReport, MobileApp, MobileAppAdUnit, MobileAppReportBinding, Publisher, RevenueRule};
use App\Services\Audit\AuditRecorder;
use App\Services\MobileApps\{AppAdsTxtService, MobileAppReporting, MobileAppService};
use App\Services\Reporting\{PerformanceMetrics, ReportDisplayQuery, RevenueRuleService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MobileAppController extends Controller
{
    private function publisher(Request $r): Publisher
    {
        return Publisher::withoutGlobalScopes()->where('organization_id', $r->user()->organization_id)->firstOrFail();
    }

    private function access(Request $r, MobileApp $mobileApp): void
    {
        if (! $r->user()->isHorusAdministrator()) {
            $publisher = $this->publisher($r);
            abort_unless($mobileApp->organization_id === $publisher->organization_id && $mobileApp->publisher_id === $publisher->id, 404);
        }
    }

    private function prefix(Request $r): string { return $r->user()->isHorusAdministrator() ? 'admin.mobile-apps' : 'publisher.mobile-apps'; }

    public function index(Request $r)
    {
        $apps = MobileApp::query()->when(! $r->user()->isHorusAdministrator(), fn ($q) => $q->where('publisher_id', $this->publisher($r)->id))
            ->with('publisher')->latest()->paginate(20);
        return view('mobile-apps.index', ['apps' => $apps, 'prefix' => $this->prefix($r)]);
    }

    public function create(Request $r)
    {
        return view('mobile-apps.form', ['prefix' => $this->prefix($r), 'publishers' => $r->user()->isHorusAdministrator()
            ? Publisher::withoutGlobalScopes()->where('status', 'ACTIVE')->orderBy('display_name')->get() : collect()]);
    }

    public function store(Request $r, MobileAppService $service)
    {
        $data = $r->validate(['name' => 'required|string|max:255', 'store_url' => 'required|url:https|max:1000',
            'package_name' => 'nullable|string|max:255', 'developer_domain' => 'required|string|max:253', 'publisher_id' => 'nullable|string|max:26']);
        $publisher = $r->user()->isHorusAdministrator()
            ? Publisher::withoutGlobalScopes()->where('status', 'ACTIVE')->findOrFail($data['publisher_id'] ?? '') : $this->publisher($r);
        $app = $service->create($publisher, $data, $r->user());
        return redirect()->route($this->prefix($r).'.show', $app)->with('status', 'App submitted. Add app-ads.txt to the developer website, then request verification.');
    }

    public function show(Request $r, MobileApp $mobileApp, AppAdsTxtService $ads, MobileAppReporting $reports)
    {
        $this->access($r, $mobileApp);
        return view('mobile-apps.show', ['app' => $mobileApp->load('adUnits', 'seller', 'reportBindings.connection'),
            'prefix' => $this->prefix($r), 'admin' => $r->user()->isHorusAdministrator(),
            'adsText' => $ads->content($mobileApp), 'adsCurrent' => $ads->current($mobileApp),
            'networks' => $r->user()->isHorusAdministrator() ? $reports->availableConnections($mobileApp)->get() : collect()]);
    }

    public function ads(Request $r, MobileApp $mobileApp, AppAdsTxtService $ads)
    {
        $this->access($r, $mobileApp);
        return response($ads->content($mobileApp))->header('Content-Type', 'text/plain; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="app-ads.txt"');
    }

    public function verify(Request $r, MobileApp $mobileApp, AppAdsTxtService $ads)
    {
        $this->access($r, $mobileApp);
        $ok = $ads->verify($mobileApp, $r->user());
        return back()->with('status', $ok ? 'app-ads.txt verified against the current master records.' : 'app-ads.txt needs attention. Check the missing records and fetch result below.');
    }

    public function domain(Request $r, MobileApp $mobileApp, MobileAppService $service)
    {
        $this->access($r, $mobileApp);
        $data = $r->validate(['developer_domain' => 'required|string|max:253']);
        $service->changeDomain($mobileApp, $data['developer_domain'], $r->user());
        return back()->with('status', 'Developer domain saved. A changed domain requires a new store review and verification.');
    }

    public function settings(Request $r, MobileApp $mobileApp, MobileAppReporting $reports, AppAdsTxtService $ads)
    {
        abort_unless($r->user()->isHorusAdministrator(), 403);
        $data = $r->validate(['status' => ['required', Rule::in(config('mobile-apps.statuses'))],
            'gam_connection_id' => 'nullable|string|max:26', 'sdk_app_id' => ['nullable', 'regex:/^ca-app-pub-[0-9]{16}~[0-9]{10}$/D'],
            'store_reviewed' => 'nullable|boolean', 'review_notes' => 'nullable|string|max:5000']);
        DB::transaction(function () use ($r, $mobileApp, $reports, $ads, $data) {
            $app = MobileApp::withoutGlobalScopes()->lockForUpdate()->findOrFail($mobileApp->id);
            $gam = ! empty($data['gam_connection_id']) ? $reports->availableConnections($app)->findOrFail($data['gam_connection_id']) : null;
            $reviewed = $r->boolean('store_reviewed');
            if ($data['status'] === 'ACTIVE' && (! $reviewed || ! $ads->current($app) || ! $gam || empty($data['sdk_app_id'])
                || ! $app->adUnits()->where('is_enabled', true)->exists()
                || $app->adUnits()->where('is_enabled', true)->get()->contains(fn ($unit) => ! str_starts_with($unit->ad_unit_path, '/'.$gam->network_code.'/')))) {
                throw ValidationException::withMessages(['status' => 'Activation requires reviewed store ownership and Google readiness, current app-ads.txt, App ID, and an enabled ad unit in the selected serving network.']);
            }
            $before = $app->only(['status', 'gam_connection_id', 'sdk_app_id']);
            $app->update(['status' => $data['status'], 'gam_connection_id' => $gam?->id, 'sdk_app_id' => $data['sdk_app_id'] ?? null,
                'review_notes' => $data['review_notes'] ?? null, 'store_reviewed_at' => $reviewed ? now() : null,
                'reviewed_by' => $reviewed ? $r->user()->id : null]);
            app(AuditRecorder::class)->record('mobile_app.settings_updated', $app->organization_id, $r->user(), $app, oldValues: $before,
                newValues: $app->only(['status', 'gam_connection_id', 'sdk_app_id', 'store_reviewed_at']));
            app(\App\Services\StaticDelivery\SupplyChainStaticPublisher::class)->queueForModel($app, $app->status === 'REJECTED');
        });
        return back()->with('status', 'App settings saved. Native SDK ad delivery is controlled in Google Ad Manager.');
    }

    public function unit(Request $r, MobileApp $mobileApp)
    {
        abort_unless($r->user()->isHorusAdministrator(), 403);
        $data = $r->validate(['id' => 'nullable|string|max:26', 'name' => 'required|string|max:255',
            'format' => ['required', Rule::in(array_keys(config('mobile-apps.formats')))],
            'ad_unit_path' => ['required', 'string', 'max:255', 'regex:#^/[0-9]+/[A-Za-z0-9_.\-/]+$#D'], 'is_enabled' => 'nullable|boolean']);
        DB::transaction(function () use ($r, $mobileApp, $data) {
            $app = MobileApp::withoutGlobalScopes()->lockForUpdate()->findOrFail($mobileApp->id);
            if (! $app->gamConnection || ! str_starts_with($data['ad_unit_path'], '/'.$app->gamConnection->network_code.'/')) {
                throw ValidationException::withMessages(['ad_unit_path' => 'Save the serving GAM network first and use an SDK ad unit path from that network.']);
            }
            if ($app->adUnits()->where('ad_unit_path', $data['ad_unit_path'])->where('format', $data['format'])
                ->when(! empty($data['id']), fn ($q) => $q->where('id', '!=', $data['id']))->exists()) {
                throw ValidationException::withMessages(['ad_unit_path' => 'This SDK path already exists for the selected format on this app. Edit its existing unit or choose another format.']);
            }
            $unit = ! empty($data['id']) ? $app->adUnits()->findOrFail($data['id']) : new MobileAppAdUnit;
            $unit->fill(['organization_id' => $app->organization_id, 'mobile_app_id' => $app->id,
                'name' => $data['name'], 'format' => $data['format'], 'ad_unit_path' => $data['ad_unit_path'], 'is_enabled' => $r->boolean('is_enabled')])->save();
            app(AuditRecorder::class)->record('mobile_app.ad_unit_saved', $app->organization_id, $r->user(), $unit, newValues: $unit->only(['name', 'format', 'ad_unit_path', 'is_enabled']));
        });
        return back()->with('status', 'SDK ad unit saved.');
    }

    public function bind(Request $r, MobileApp $mobileApp, MobileAppReporting $reports)
    {
        $data = $r->validate(['gam_connection_id' => 'required|string|max:26', 'ad_unit' => 'required|string|max:255']);
        $reports->bind($mobileApp, $data['gam_connection_id'], $data['ad_unit'], $r->user());
        return back()->with('status', 'Reporting source verified. Automatic synchronization is scheduled.');
    }

    public function toggle(Request $r, MobileApp $mobileApp, MobileAppReportBinding $binding)
    {
        abort_unless($r->user()->isHorusAdministrator() && $binding->mobile_app_id === $mobileApp->id, 404);
        $binding->connection->update(['is_enabled' => $r->boolean('is_enabled'), 'status' => $r->boolean('is_enabled') ? 'ACTIVE' : 'DISABLED']);
        app(AuditRecorder::class)->record('mobile_app.reporting_toggled', $mobileApp->organization_id, $r->user(), $binding,
            newValues: ['is_enabled' => $r->boolean('is_enabled')]);
        return back()->with('status', 'Reporting status updated; historical earnings and unit ownership are retained.');
    }

    public function share(Request $r, MobileApp $mobileApp, RevenueRuleService $rules)
    {
        abort_unless($r->user()->isHorusAdministrator(), 403);
        $data = $r->validate(['publisher_share' => 'required|numeric|min:0|max:100', 'effective_from' => 'required|date_format:Y-m-d', 'reason' => 'required|string|min:12|max:2000']);
        $bp = (int) round((float) $data['publisher_share'] * 100);
        $attributes = ['name' => 'Mobile app revenue share', 'scope_type' => 'MOBILE_APP', 'scope_id' => $mobileApp->id,
            'publisher_share_bp' => $bp, 'horus_share_bp' => 10000 - $bp, 'mcm_partner_share_bp' => 0,
            'effective_from' => $data['effective_from'], 'effective_to' => null, 'reason' => $data['reason'], 'priority' => 100, 'currency' => null];
        $rule = RevenueRule::withoutGlobalScopes()->where('scope_type', 'MOBILE_APP')->where('scope_id', $mobileApp->id)->where('name', $attributes['name'])->first();
        if ($rule) $rules->changeRule($rule, $attributes, $r->user());
        else $rules->createRule($attributes, $r->user());
        return back()->with('status', 'App revenue share saved with an effective date and audit history.');
    }

    public function reports(Request $r, MobileApp $mobileApp)
    {
        $this->access($r, $mobileApp);
        $dates = $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $from = $dates['from'] ?? now()->startOfMonth()->toDateString(); $to = $dates['to'] ?? now()->toDateString();
        if ($from > $to) throw ValidationException::withMessages(['from' => 'Choose an ordered date range.']);
        $publisher = ! $r->user()->isHorusAdministrator(); $currency = config('reporting.canonical_currency', 'USD');
        $facts = ReportDisplayQuery::constrain(DailyReport::withoutGlobalScopes(), true)
            ->where('organization_id', $mobileApp->organization_id)->where('currency', $currency)
            ->whereHas('dimension', fn ($q) => $q->where('mobile_app_id', $mobileApp->id)->where('publisher_id', $mobileApp->publisher_id)->where('organization_id', $mobileApp->organization_id))
            ->whereDate('report_date', '>=', $from)->whereDate('report_date', '<=', $to)->get();
        $field = $publisher ? 'publisher_earnings_minor' : 'gross_revenue_minor';
        $aggregate = fn ($items) => app(PerformanceMetrics::class)->summarize($items, $field) + ['earnings_minor' => (int) $items->sum($field), 'ad_requests' => (int) $items->sum('ad_requests'), 'has_estimates' => $items->contains(fn ($row) => $row->finality->value === 'ESTIMATED')];
        $days = $facts->groupBy(fn ($row) => $row->report_date->toDateString())->sortKeys()->map(fn ($items, $date) => ['label' => $date, ...$aggregate($items)])->values();
        if ($r->boolean('export')) return response()->streamDownload(function () use ($days) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Date', 'Ad requests', 'Impressions', 'Clicks', 'Revenue (minor units)'], escape: '');
            foreach ($days as $day) fputcsv($handle, [$day['label'], $day['ad_requests'], $day['impressions'], $day['clicks'], $day['earnings_minor']], escape: '');
            fclose($handle);
        }, 'mobile-app-report.csv', ['Content-Type' => 'text/csv']);
        return view('mobile-apps.reports', ['app' => $mobileApp, 'prefix' => $this->prefix($r), 'days' => $days,
            'totals' => $aggregate($facts), 'from' => $from, 'to' => $to, 'publisherView' => $publisher,
            'currency' => $currency, 'hasEstimates' => $facts->contains(fn ($fact) => $fact->finality->value === 'ESTIMATED')]);
    }
}
