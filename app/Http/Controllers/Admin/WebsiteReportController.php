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

        $metrics = $request->selectedMetrics($summary);
        $period = \App\Support\PeriodComparison::previous($summary['from'], $summary['to']);
        $previous = $reports->summary($site, $period['from'], $period['to'], includeEstimates: true);

        return view('admin.reporting.website', [
            'previousPeriod' => $period, 'previous' => $previous['available'] ? $previous : null,
            'opportunities' => app(\App\Services\Reporting\AdFormatOpportunityService::class)->forSite($site, publisherView: false),
            'previousAdFormats' => app(AdFormatBreakdownService::class)->summary(collect([$site]), $period['from'], $period['to'], publisherView: false),
            'site' => $site->load('publisher'), 'summary' => $summary, 'reportMetrics' => $metrics,
            // Same period and website, split by Google inventory format (read-only projection of the same ledger).
            'adFormats' => app(AdFormatBreakdownService::class)->summary(collect([$site]), $summary['from'], $summary['to'], publisherView: false),
            'adFormatMetrics' => array_values(array_diff($metrics, ['unfilled_impressions'])),
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
        $dimension = $this->dimension($request);
        $breakdown = $breakdowns->summary($selected, $request->validated('from'), $request->validated('to'), publisherView: false,
            publisher: $siteId ? null : $publisher, dimension: $dimension);
        $metrics = array_values(array_diff($request->selectedMetrics(['has_site_ad_exchange' => true]), ['unfilled_impressions']));
        if ($request->validated('export') === 'csv') {
            return app(AdFormatBreakdownCsv::class)->download($breakdown, $metrics, false);
        }

        $period = \App\Support\PeriodComparison::previous($breakdown['from'], $breakdown['to']);

        return view('admin.reporting.ad-formats', [
            'previousPeriod' => $period,
            'previousBreakdown' => $breakdowns->summary($selected, $period['from'], $period['to'], publisherView: false, publisher: $siteId ? null : $publisher, dimension: $dimension),
            'drops' => app(\App\Services\Reporting\AdFormatDropDetector::class)->detect(\Carbon\CarbonImmutable::now('UTC')->subDay()->toDateString(), $selected->pluck('id')->all()),
            'ranking' => app(\App\Services\Reporting\AdFormatRankingService::class)->rank($selected->loadMissing('publisher'), $breakdown['from'], $breakdown['to']),
            'publisher' => $publisher, 'breakdown' => $breakdown, 'reportMetrics' => $metrics,
            'sites' => $sites, 'selectedSite' => $siteId,
            'syncStatuses' => $selected->mapWithKeys(fn (Site $site) => [$site->id => app(SiteGamAdFormatSynchronizer::class)->status($site)]),
        ]);
    }

    /** Network-wide breakdown by ad format, with a publisher selector. */
    public function adFormatsOverview(ReportPeriodRequest $request, AdFormatBreakdownService $breakdowns): View|StreamedResponse|\Illuminate\Http\RedirectResponse
    {
        $publisherId = $request->validate(['publisher' => ['nullable', 'string', 'max:64']])['publisher'] ?? null;
        if ($publisherId) {
            $publisher = Publisher::query()->findOrFail($publisherId);

            return redirect()->route('admin.reporting.publishers.ad-formats', array_filter(['publisher' => $publisher,
                'from' => $request->validated('from'), 'to' => $request->validated('to'), 'by' => $request->query('by')]));
        }
        $sites = Site::query()->with('publisher')->orderBy('display_name')->orderBy('id')->get();
        $dimension = $this->dimension($request);
        $breakdown = $breakdowns->summary($sites, $request->validated('from'), $request->validated('to'), publisherView: false, dimension: $dimension);
        $metrics = array_values(array_diff($request->selectedMetrics(['has_site_ad_exchange' => true]), ['unfilled_impressions']));
        if ($request->validated('export') === 'csv') {
            return app(AdFormatBreakdownCsv::class)->download($breakdown, $metrics, false);
        }
        $bound = $sites->filter(fn (Site $site) => $site->currentGamReportBinding()->exists());

        $period = \App\Support\PeriodComparison::previous($breakdown['from'], $breakdown['to']);

        return view('admin.reporting.ad-formats', [
            'previousPeriod' => $period,
            'previousBreakdown' => $breakdowns->summary($sites, $period['from'], $period['to'], publisherView: false, dimension: $dimension),
            'drops' => app(\App\Services\Reporting\AdFormatDropDetector::class)->detect(\Carbon\CarbonImmutable::now('UTC')->subDay()->toDateString(), $sites->pluck('id')->all()),
            'ranking' => app(\App\Services\Reporting\AdFormatRankingService::class)->rank($sites->loadMissing('publisher'), $breakdown['from'], $breakdown['to']),
            'publisher' => null, 'breakdown' => $breakdown, 'reportMetrics' => $metrics,
            'sites' => $sites, 'selectedSite' => null,
            'publishers' => Publisher::query()->whereHas('sites')->orderBy('display_name')->get(['id', 'display_name']),
            'syncStatuses' => $bound->mapWithKeys(fn (Site $site) => [$site->id => app(SiteGamAdFormatSynchronizer::class)->status($site)]),
        ]);
    }

    /** Breakdown dimension selected on the page: ad format (default), country or device. */
    private function dimension(\Illuminate\Http\Request $request): string
    {
        return ['country' => 'COUNTRY', 'device' => 'DEVICE'][$request->query('by')] ?? 'FORMAT';
    }
}
