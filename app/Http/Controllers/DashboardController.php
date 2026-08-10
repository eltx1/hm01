<?php

namespace App\Http\Controllers;

use App\Enums\CampaignStatus;
use App\Enums\OrganizationType;
use App\Models\Advertiser;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Publisher;
use App\Models\Site;
use App\Services\ControlPlane\ActionCenter;
use App\Services\Reporting\UnifiedReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, UnifiedReportService $reports, ActionCenter $actionCenter): View
    {
        return match ($request->user()->organization->type) {
            OrganizationType::HorusMedia => $this->administrator($request, $reports, $actionCenter),
            OrganizationType::Publisher => $this->publisher($request, $reports, $actionCenter),
            OrganizationType::Advertiser => view('dashboards.advertiser', [
                'campaigns' => Campaign::query()->latest()->limit(8)->get(),
                'activeCampaigns' => Campaign::query()->whereIn('status', [CampaignStatus::Scheduled->value, CampaignStatus::Active->value])->count(),
                'reporting' => $reports->advertiserSummary($request->user()->organization->advertiser),
            ]),
            OrganizationType::Partner => view('dashboards.partner'),
        };
    }

    private function administrator(Request $request, UnifiedReportService $reports, ActionCenter $actionCenter): View
    {
        $user = $request->user();

        return view('dashboards.admin', [
            'totalPublishers' => $user->hasPermission('publishers.view') ? Publisher::withoutGlobalScopes()->count() : null,
            'totalAdvertisers' => $user->hasPermission('advertisers.view') ? Advertiser::withoutGlobalScopes()->count() : null,
            'totalWebsites' => $user->hasPermission('sites.view') ? Site::withoutGlobalScopes()->count() : null,
            'activeCampaigns' => $user->hasPermission('campaigns.view') || $user->hasPermission('campaigns.review')
                ? Campaign::withoutGlobalScopes()->whereIn('status', [CampaignStatus::Scheduled->value, CampaignStatus::Active->value, CampaignStatus::Paused->value])->count()
                : null,
            'reporting' => $user->hasPermission('reporting.admin.view') ? $reports->adminSummary() : null,
            'showInternalMargin' => $user->hasPermission('finance.internal_margin.view'),
            'failedJobs' => $user->hasPermission('operations.view') ? DB::table('failed_jobs')->latest('failed_at')->limit(10)->get() : collect(),
            'auditEvents' => $user->hasPermission('audit.view') ? AuditLog::query()->latest()->limit(10)->get() : collect(),
            'actionItems' => $actionCenter->items($user),
        ]);
    }

    private function publisher(Request $request, UnifiedReportService $reports, ActionCenter $actionCenter): View
    {
        $publisher = $request->user()->organization->publisher()->with([
            'sites' => fn ($query) => $query->latest(),
            'contracts' => fn ($query) => $query->latest(),
            'paymentProfile',
        ])->firstOrFail();
        $reporting = $request->user()->hasPermission('reporting.publisher.view')
            ? $reports->publisherSummary($publisher)
            : ['impressions' => 0, 'revenue_minor' => 0, 'payment_balance_minor' => 0, 'statements' => collect()];

        return view('dashboards.publisher', [
            'publisher' => $publisher,
            'reporting' => $reporting,
            'actionItems' => $actionCenter->items($request->user()),
        ]);
    }
}
