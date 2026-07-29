<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Advertiser;
use App\Models\Campaign;
use App\Models\CampaignCreative;
use App\Models\CampaignNetworkInstance;
use App\Models\User;
use App\Services\Campaigns\AdvertiserAccountService;
use App\Services\Campaigns\CampaignDeploymentService;
use App\Services\Campaigns\CampaignNetworkPlanner;
use App\Services\Campaigns\CampaignReportingService;
use App\Services\Campaigns\CampaignWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DirectCampaignController extends Controller
{
    public function index(): View
    {
        return view('admin.campaigns.index', [
            'campaigns' => Campaign::withoutGlobalScopes()->with(['advertiser', 'networkInstances'])->withCount(['sites', 'creatives'])->latest()->paginate(30),
            'pendingAdvertisers' => Advertiser::withoutGlobalScopes()->where('status', 'PENDING')->count(),
        ]);
    }

    public function show(Campaign $campaign, CampaignNetworkPlanner $planner, CampaignReportingService $reporting): View
    {
        $campaign->load(['advertiser.billingProfiles', 'goals', 'targets', 'sites.site.publisher', 'placements.placement', 'creatives.files', 'budget', 'networkInstances.connection', 'approvalLogs.actor', 'invoices']);
        $preview = null;
        try { $preview = $planner->preview($campaign); } catch (\Throwable $exception) { $preview = ['issues' => [$exception->getMessage()], 'estimatedObjects' => 0, 'pendingObjects' => 0, 'plans' => []]; }
        return view('admin.campaigns.show', ['campaign' => $campaign->fresh(['networkInstances.connection']), 'preview' => $preview, 'report' => $reporting->summary($campaign)]);
    }

    public function linkAdvertiserUser(Request $request, Advertiser $advertiser, AdvertiserAccountService $accounts): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'ulid', 'exists:users,id'],
            'role' => ['required', 'string', 'max:40'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);
        $user = User::withoutGlobalScopes()->findOrFail($data['user_id']);
        $accounts->linkUser($advertiser, $user, $data['role'], $request->boolean('is_primary'), $request->user());
        return back()->with('status', 'Advertiser user linked.');
    }

    public function reviewAdvertiser(Request $request, Advertiser $advertiser, AdvertiserAccountService $accounts): RedirectResponse
    {
        $data = $request->validate(['approved' => ['required', 'boolean'], 'notes' => ['nullable', 'string', 'max:10000']]);
        $accounts->review($advertiser, (bool) $data['approved'], $request->user(), $data['notes'] ?? null);
        return back()->with('status', 'Advertiser review recorded.');
    }

    public function reviewCreative(Request $request, Campaign $campaign, CampaignCreative $campaignCreative, CampaignWorkflowService $workflow): RedirectResponse
    {
        abort_unless($campaignCreative->campaign_id === $campaign->id, 404);
        $data = $request->validate(['approved' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:10000']]);
        $workflow->reviewCreative($campaignCreative, (bool) $data['approved'], $request->user(), $data['reason'] ?? null);
        return back()->with('status', 'Creative review recorded.');
    }

    public function approve(Request $request, Campaign $campaign, CampaignWorkflowService $workflow): RedirectResponse
    {
        $workflow->approve($campaign, $request->user());
        return back()->with('status', 'Campaign approved and invoice issued.');
    }

    public function reject(Request $request, Campaign $campaign, CampaignWorkflowService $workflow): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:10000']]);
        $workflow->reject($campaign, $request->user(), $data['reason']);
        return back()->with('status', 'Campaign rejected.');
    }

    public function schedule(Request $request, Campaign $campaign, CampaignWorkflowService $workflow): RedirectResponse
    {
        $workflow->schedule($campaign, $request->user());
        return back()->with('status', 'Campaign scheduled locally. Deploy the reviewed GAM plan when ready.');
    }

    public function pause(Request $request, Campaign $campaign, CampaignWorkflowService $workflow, CampaignDeploymentService $deployment): RedirectResponse
    {
        $campaign = $workflow->pause($campaign, $request->user());
        $results = $deployment->pauseAll($campaign->load('networkInstances.connection'), $request->user());
        return back()->with(in_array(false, $results, true) ? 'error' : 'status', in_array(false, $results, true) ? 'Campaign paused locally; at least one network requires retry.' : 'Campaign and all remote instances paused.');
    }

    public function resume(Request $request, Campaign $campaign, CampaignWorkflowService $workflow, CampaignDeploymentService $deployment): RedirectResponse
    {
        $campaign = $workflow->resume($campaign, $request->user());
        $results = $deployment->resumeAll($campaign->load('networkInstances.connection'), $request->user());
        return back()->with(in_array(false, $results, true) ? 'error' : 'status', in_array(false, $results, true) ? 'Campaign resumed locally; at least one network requires retry.' : 'Campaign and all remote instances resumed.');
    }

    public function complete(Request $request, Campaign $campaign, CampaignWorkflowService $workflow, CampaignDeploymentService $deployment): RedirectResponse
    {
        $campaign = $workflow->complete($campaign, $request->user());
        $deployment->completeAll($campaign->load('networkInstances.connection'), $request->user());
        return back()->with('status', 'Campaign ended and remote instances stopped.');
    }

    public function bonus(Request $request, Campaign $campaign, CampaignWorkflowService $workflow): RedirectResponse
    {
        $data = $request->validate(['units' => ['required', 'integer', 'min:1'], 'note' => ['required', 'string', 'max:5000']]);
        $workflow->addBonus($campaign, (int) $data['units'], $data['note'], $request->user());
        return back()->with('status', 'Bonus inventory added.');
    }

    public function targeting(Request $request, Campaign $campaign, CampaignWorkflowService $workflow): RedirectResponse
    {
        $data = $request->validate([
            'countries' => ['nullable', 'array'], 'countries.*' => ['string', 'size:2'],
            'devices' => ['nullable', 'array'], 'devices.*' => ['string', 'in:DESKTOP,TABLET,MOBILE,CONNECTED_TV'],
            'site_ids' => ['required', 'array', 'min:1'], 'site_ids.*' => ['ulid', 'exists:sites,id'],
            'placement_ids' => ['nullable', 'array'], 'placement_ids.*' => ['ulid', 'exists:placements,id'],
        ]);
        $workflow->changeTargeting($campaign, $data, $request->user());
        return back()->with('status', 'Campaign targeting changed; deployment plan invalidated for safe regeneration.');
    }

    public function dryRun(Request $request, Campaign $campaign, CampaignDeploymentService $deployment): RedirectResponse
    {
        $result = $deployment->deployCampaign($campaign, $request->user(), true, false);
        return back()->with('status', 'GAM dry-run completed for '.count($result['results']).' network instance(s).');
    }

    public function deploy(Request $request, Campaign $campaign, CampaignDeploymentService $deployment): RedirectResponse
    {
        $request->validate(['confirm_external_writes' => ['accepted']]);
        $result = $deployment->deployCampaign($campaign, $request->user(), false, true);
        $failed = collect($result['results'])->contains(fn (array $row) => ! ($row['success'] ?? false));
        return back()->with($failed ? 'error' : 'status', $failed ? 'Deployment completed with isolated network failures. Retry only the failed network.' : 'Campaign deployed idempotently to every selected GAM network.');
    }

    public function retry(Request $request, Campaign $campaign, CampaignNetworkInstance $campaignNetworkInstance, CampaignDeploymentService $deployment): RedirectResponse
    {
        abort_unless($campaignNetworkInstance->campaign_id === $campaign->id, 404);
        $request->validate(['confirm_external_writes' => ['accepted']]);
        $result = $deployment->deployInstance($campaignNetworkInstance, $request->user(), false, true);
        return back()->with(($result['success'] ?? false) ? 'status' : 'error', ($result['success'] ?? false) ? 'Failed network instance retried successfully.' : 'The network retry remains failed: '.($result['error'] ?? implode(' ', $result['issues'] ?? [])));
    }

    public function synchronize(Request $request, Campaign $campaign, CampaignReportingService $reporting): RedirectResponse
    {
        $results = $reporting->requestDeliveryReports($campaign->load('networkInstances.connection'));
        return back()->with('status', 'Requested aggregated GAM delivery reports for '.count($results).' network instance(s).');
    }

    public function reconcile(Request $request, Campaign $campaign, CampaignReportingService $reporting): RedirectResponse
    {
        $results = $reporting->reconcile($campaign->load('networkInstances.connection'));
        $drift = collect($results)->where('drift', true)->count();
        return back()->with($drift ? 'error' : 'status', $drift ? "Remote reconciliation found drift in {$drift} network instance(s)." : 'Campaign remote objects are reconciled with the local source of truth.');
    }
}
