<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationType;
use App\Models\Advertiser;
use App\Models\AuditLog;
use App\Models\Publisher;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        return match ($request->user()->organization->type) {
            OrganizationType::HorusMedia => view('dashboards.admin', [
                'totalPublishers' => Publisher::withoutGlobalScopes()->count(),
                'totalAdvertisers' => Advertiser::withoutGlobalScopes()->count(),
                'totalWebsites' => Site::withoutGlobalScopes()->count(),
                'activeCampaigns' => 0,
                'estimatedMonthlyRevenue' => 0,
                'failedJobs' => DB::table('failed_jobs')->latest('failed_at')->limit(10)->get(),
                'auditEvents' => AuditLog::query()->latest()->limit(10)->get(),
            ]),
            OrganizationType::Publisher => view('dashboards.publisher'),
            OrganizationType::Advertiser => view('dashboards.advertiser'),
            OrganizationType::Partner => view('dashboards.partner'),
        };
    }
}
