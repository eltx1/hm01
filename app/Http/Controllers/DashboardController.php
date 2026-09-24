<?php

namespace App\Http\Controllers;

use App\Enums\CampaignStatus;
use App\Enums\OrganizationType;
use App\Models\AiProviderConnection;
use App\Models\Advertiser;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Publisher;
use App\Models\Site;
use App\Models\ThothSetting;
use App\Services\ControlPlane\ActionCenter;
use App\Services\Reporting\PublisherFinanceService;
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
        $canonicalCurrency = strtoupper((string) config('reporting.canonical_currency', 'USD'));

        return view('dashboards.admin', [
            'totalPublishers' => $user->hasPermission('publishers.view') ? Publisher::withoutGlobalScopes()->count() : null,
            'totalAdvertisers' => $user->hasPermission('advertisers.view') ? Advertiser::withoutGlobalScopes()->count() : null,
            'totalWebsites' => $user->hasPermission('sites.view') ? Site::withoutGlobalScopes()->count() : null,
            'activeCampaigns' => $user->hasPermission('campaigns.view') || $user->hasPermission('campaigns.review')
                ? Campaign::withoutGlobalScopes()->whereIn('status', [CampaignStatus::Scheduled->value, CampaignStatus::Active->value, CampaignStatus::Paused->value])->count()
                : null,
            'reporting' => $user->hasPermission('reporting.admin.view') ? $reports->adminSummary(currency: $canonicalCurrency) : null,
            'showInternalMargin' => $user->hasPermission('finance.internal_margin.view'),
            'failedJobs' => $user->hasPermission('operations.view') ? DB::table('failed_jobs')->latest('failed_at')->limit(10)->get() : collect(),
            'auditEvents' => $user->hasPermission('audit.view') ? AuditLog::query()->latest()->limit(10)->get() : collect(),
            'actionItems' => $actionCenter->items($user),
            'aiSettings' => $user->hasPermission('thoth.settings.view') ? ThothSetting::current() : null,
            'aiConnections' => $user->hasPermission('thoth.settings.view')
                ? AiProviderConnection::query()->get()->keyBy('provider')
                : collect(),
        ]);
    }

    private function publisher(Request $request, UnifiedReportService $reports, ActionCenter $actionCenter): View
    {
        $publisher = $request->user()->organization->publisher()->with([
            'sites' => fn ($query) => $query->latest(),
            'contracts' => fn ($query) => $query->latest(),
            'paymentProfile',
        ])->firstOrFail();
        $canonicalCurrency = strtoupper((string) config('reporting.canonical_currency', 'USD'));
        $reporting = $request->user()->hasPermission('finance.publisher.view_own')
            ? app(PublisherFinanceService::class)->dashboard($publisher)
            : [
                'canonical_currency' => $canonicalCurrency,
                'primary' => [
                    'currency' => $canonicalCurrency,
                    'today_available' => false,
                    'today_impressions' => 0,
                    'today_clicks' => 0,
                    'today_estimated_earnings_minor' => 0,
                    'finalized_earnings_minor' => 0,
                    'statement_balance_due_minor' => 0,
                ],
                'impressions' => 0,
                'statements' => collect(),
                'legacy_currency_count' => 0,
            ];

        return view('dashboards.publisher', [
            'publisher' => $publisher,
            'reporting' => $reporting,
            'actionItems' => $actionCenter->items($request->user()),
        ]);
    }
}
