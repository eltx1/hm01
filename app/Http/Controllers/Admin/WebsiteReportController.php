<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportPeriodRequest;
use App\Models\Publisher;
use App\Models\Site;
use App\Services\Reporting\AdFormatBreakdownCsv;
use App\Services\Reporting\AdFormatBreakdownService;
use App\Services\Reporting\AdminWebsitePerformanceService;
use App\Services\Reporting\PerformanceReportCsv;
use App\Services\Reporting\SiteGamAdFormatSynchronizer;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WebsiteReportController extends Controller
{
    public function index(ReportPeriodRequest $request, AdminWebsitePerformanceService $reports): View
    {
        $search = $request->validate(['q' => ['nullable', 'string', 'max:150']])['q'] ?? '';
        $sites = Site::query()->with('publisher')
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('display_name', 'like', '%'.$search.'%')
                    ->orWhere('primary_domain', 'like', '%'.$search.'%')
                    ->orWhereHas('publisher', fn ($publisher) => $publisher->where('display_name', 'like', '%'.$search.'%'));
            }))->orderBy('display_name')->orderBy('id')->paginate(24)->withQueryString();

        $totals = $reports->summaries($sites->getCollection(), $request->validated('from'), $request->validated('to'), includeEstimates: true);

        return view('admin.reporting.websites', [
            'sites' => $sites, 'search' => $search,
            'from' => $request->validated('from'), 'to' => $request->validated('to'),
            'metrics' => $request->selectedMetrics([
                'has_site_ad_exchange' => $totals->contains('has_site_ad_exchange', true),
                'has_other_sources' => $totals->contains('has_other_sources', true),
            ]), 'currency' => $reports->currency(),
            'totals' => $totals,
            'coverage' => app(\App\Services\Reporting\ReportCoverageService::class)->forPeriod($request->validated('from'), $request->validated('to'), $reports->currency(), siteIds: $sites->getCollection()->pluck('id')->all()),
        ]);
    }

    public function show(ReportPeriodRequest $request, Site $site, AdminWebsitePerformanceService $reports): View|StreamedResponse
    {
        $summary = $reports->summary($site, $request->validated('from'), $request->validated('to'), includeEstimates: true);
        $summary['coverage'] = app(\App\Services\Reporting\ReportCoverageService::class)->forPeriod($request->validated('from'), $request->validated('to'), $reports->currency(), $site);
        if ($request->validated('export') === 'video_csv') {
            return app(\App\Services\Reporting\VideoReportCsv::class)->download($summary['video'], false);
        }
        if ($request->validated('export') === 'csv') {
            return app(PerformanceReportCsv::class)->download($summary['days'], $request->selectedMetrics($summary), $summary['currency'], false, includeFinality: true);
        }

        return view('admin.reporting.website', [
            'site' => $site->load('publisher'), 'summary' => $summary, 'reportMetrics' => $request->selectedMetrics($summary),
        ]);
    }

    /** The publisher's report, broken down by Google inventory format (all websites or one). */
    public function adFormats(ReportPeriodRequest $request, Publisher $publisher, AdFormatBreakdownService $breakdowns): View|StreamedResponse
    {
        $sites = Site::withoutGlobalScopes()->where('organization_id', $publisher->organization_id)
            ->where('publisher_id', $publisher->id)->orderBy('display_name')->orderBy('id')->get();
        $siteId = $request->validate(['site' => ['nullable', 'string', 'max:64']])['site'] ?? null;
        $selected = $siteId ? $sites->where('id', $siteId)->values() : $sites;
        abort_if($siteId && $selected->isEmpty(), 404);
        $breakdown = $breakdowns->summary($selected, $request->validated('from'), $request->validated('to'), publisherView: false,
            publisher: $siteId ? null : $publisher);
        $metrics = array_values(array_diff($request->selectedMetrics(['has_site_ad_exchange' => true]), ['unfilled_impressions']));
        if ($request->validated('export') === 'csv') {
            return app(AdFormatBreakdownCsv::class)->download($breakdown, $metrics, false);
        }

        return view('admin.reporting.ad-formats', [
            'publisher' => $publisher, 'breakdown' => $breakdown, 'reportMetrics' => $metrics,
            'sites' => $sites, 'selectedSite' => $siteId,
            'syncStatuses' => $selected->mapWithKeys(fn (Site $site) => [$site->id => app(SiteGamAdFormatSynchronizer::class)->status($site)]),
        ]);
    }
}
