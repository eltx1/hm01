<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportPeriodRequest;
use App\Models\Site;
use App\Services\Reporting\AdminWebsitePerformanceService;
use App\Services\Reporting\PerformanceReportCsv;
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

        return view('admin.reporting.websites', [
            'sites' => $sites, 'search' => $search,
            'from' => $request->validated('from'), 'to' => $request->validated('to'),
            'metrics' => $request->selectedMetrics(), 'currency' => $reports->currency(),
            'totals' => $reports->summaries($sites->getCollection(), $request->validated('from'), $request->validated('to')),
        ]);
    }

    public function show(ReportPeriodRequest $request, Site $site, AdminWebsitePerformanceService $reports): View|StreamedResponse
    {
        $summary = $reports->summary($site, $request->validated('from'), $request->validated('to'));
        if ($request->validated('export') === 'csv') {
            return app(PerformanceReportCsv::class)->download($summary['days'], $request->selectedMetrics(), $summary['currency'], false);
        }

        return view('admin.reporting.website', [
            'site' => $site->load('publisher'), 'summary' => $summary, 'reportMetrics' => $request->selectedMetrics(),
        ]);
    }
}
