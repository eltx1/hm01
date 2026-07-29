<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Services\Reporting\UnifiedReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportingController extends Controller
{
    public function index(Request $request, UnifiedReportService $reports): View
    {
        $advertiser = $request->user()->organization?->advertiser;
        abort_unless($advertiser, 404);

        return view('advertiser.reporting.index', [
            'summary' => $reports->advertiserSummary(
                $advertiser,
                $request->date('from') ?: now()->startOfMonth(),
                $request->date('to') ?: now(),
            ),
            'advertiser' => $advertiser,
        ]);
    }
}
