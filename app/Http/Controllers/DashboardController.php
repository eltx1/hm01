<?php

namespace App\Http\Controllers;

use App\Enums\CampaignStatus;
use App\Enums\OrganizationType;
use App\Models\Advertiser;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Publisher;
use App\Models\Site;
use App\Services\Reporting\UnifiedReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, UnifiedReportService $reports): View
    {
        return match ($request->user()->organization->type) {
            OrganizationType::HorusMedia => view('dashboards.admin', [
                'totalPublishers' => Publisher::withoutGlobalScopes()->count(),
                'totalAdvertisers' => Advertiser::withoutGlobalScopes()->count(),
                'totalWebsites' => Site::withoutGlobalScopes()->count(),
                'activeCampaigns' => Campaign::withoutGlobalScopes()->whereIn('status', [CampaignStatus::Scheduled->value, CampaignStatus::Active->value, CampaignStatus::Paused->value])->count(),
                'reporting' => $reports->adminSummary(),
                'failedJobs' => DB::table('failed_jobs')->latest('failed_at')->limit(10)->get(),
                'auditEvents' => AuditLog::query()->latest()->limit(10)->get(),
            ]),
            OrganizationType::Publisher => view('dashboards.publisher', [
                'reporting' => $reports->publisherSummary($request->user()->organization->publisher),
            ]),
            OrganizationType::Advertiser => view('dashboards.advertiser', [
                'campaigns' => Campaign::query()->latest()->limit(8)->get(),
                'activeCampaigns' => Campaign::query()->whereIn('status', [CampaignStatus::Scheduled->value, CampaignStatus::Active->value])->count(),
                'reporting' => $reports->advertiserSummary($request->user()->organization->advertiser),
            ]),
            OrganizationType::Partner => view('dashboards.partner'),
        };
    }
}
