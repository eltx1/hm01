<?php

namespace App\Http\Controllers\Advertiser;

use App\Enums\CampaignCreativeType;
use App\Enums\CampaignPricingModel;
use App\Http\Controllers\Controller;
use App\Models\Advertiser;
use App\Models\AdvertiserInvoice;
use App\Models\Campaign;
use App\Models\CampaignCreative;
use App\Models\Placement;
use App\Models\Site;
use App\Services\Campaigns\AdvertiserAccountService;
use App\Services\Campaigns\AdvertiserInvoiceService;
use App\Services\Campaigns\CampaignCreativeService;
use App\Services\Campaigns\CampaignDeliveryCapabilityService;
use App\Services\Campaigns\CampaignReportingService;
use App\Services\Campaigns\CampaignWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use JsonException;

class CampaignController extends Controller
{
    public function __construct(private readonly CampaignDeliveryCapabilityService $deliveryCapability)
    {
    }

    public function index(Request $request): View
    {
        $advertiser = $this->advertiser($request);
        return view('advertiser.campaigns.index', [
            'advertiser' => $advertiser,
            'campaigns' => Campaign::query()->where('advertiser_id', $advertiser->id)->withCount(['sites', 'creatives', 'networkInstances'])->latest()->paginate(20),
            'invoices' => AdvertiserInvoice::query()->where('advertiser_id', $advertiser->id)->latest()->limit(10)->get(),
            'campaignCreationEnabled' => $this->deliveryCapability->featureEnabled(),
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($this->deliveryCapability->featureEnabled(), 403, 'Advertiser Campaign creation is currently unavailable.');
        return $this->form($request, new Campaign(['currency' => 'USD', 'pricing_model' => CampaignPricingModel::Cpm, 'starts_at' => now()->addDay(), 'ends_at' => now()->addMonth()]));
    }

    public function store(Request $request, CampaignWorkflowService $workflow): RedirectResponse
    {
        $campaign = $workflow->create($this->advertiser($request), $this->validatedCampaign($request), $request->user());
        return redirect()->route('advertiser.campaigns.show', $campaign)->with('status', 'Campaign draft created.');
    }

    public function edit(Request $request, Campaign $campaign): View
    {
        $this->owns($request, $campaign);
        return $this->form($request, $campaign->load(['goals', 'targets', 'sites', 'placements']));
    }

    public function update(Request $request, Campaign $campaign, CampaignWorkflowService $workflow): RedirectResponse
    {
        $this->owns($request, $campaign);
        $workflow->update($campaign, $this->validatedCampaign($request), $request->user());
        return redirect()->route('advertiser.campaigns.show', $campaign)->with('status', 'Campaign updated.');
    }

    public function show(Request $request, Campaign $campaign, CampaignReportingService $reporting): View
    {
        $this->owns($request, $campaign);
        $campaign->load(['goals', 'targets', 'sites.site.publisher', 'placements.placement', 'creatives.files', 'budget', 'networkInstances.connection', 'approvalLogs.actor', 'invoices']);
        return view('advertiser.campaigns.show', [
            'campaign' => $campaign,
            'report' => $reporting->summary($campaign),
            'deliveryCapability' => $this->deliveryCapability->evaluate($campaign, in_array($campaign->status->value, ['DRAFT', 'REJECTED'], true))->forCustomer(),
        ]);
    }

    public function submit(Request $request, Campaign $campaign, CampaignWorkflowService $workflow): RedirectResponse
    {
        $this->owns($request, $campaign);
        $workflow->submit($campaign, $request->user());
        return back()->with('status', 'Campaign submitted for Horus Media review.');
    }

    public function creative(Request $request, Campaign $campaign, CampaignCreativeService $creatives): RedirectResponse
    {
        $this->owns($request, $campaign);
        $data = $this->validatedCreative($request);
        $creatives->create($campaign, $data, $request->file('creative_file'), $request->user());
        return back()->with('status', 'Creative uploaded and validated.');
    }

    public function replaceCreative(Request $request, Campaign $campaign, CampaignCreative $campaignCreative, CampaignCreativeService $creatives): RedirectResponse
    {
        $this->owns($request, $campaign);
        abort_unless($campaignCreative->campaign_id === $campaign->id, 404);
        $creatives->create($campaign, $this->validatedCreative($request), $request->file('creative_file'), $request->user(), $campaignCreative);
        return back()->with('status', 'Replacement creative uploaded for review.');
    }

    public function billingProfile(Request $request, AdvertiserAccountService $accounts): RedirectResponse
    {
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:255'], 'billing_email' => ['required', 'email'],
            'currency' => ['required', 'string', 'size:3'], 'country_code' => ['required', 'string', 'size:2'],
            'address_line_1' => ['required', 'string', 'max:255'], 'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'], 'region' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:40'], 'tax_identifier' => ['nullable', 'string', 'max:255'],
            'payment_terms_days' => ['nullable', 'integer', 'between:0,365'], 'is_default' => ['sometimes', 'boolean'],
        ]);
        $data['currency'] = strtoupper($data['currency']);
        $data['country_code'] = strtoupper($data['country_code']);
        $data['is_default'] = $request->boolean('is_default', true);
        $accounts->saveBillingProfile($this->advertiser($request), $data, $request->user());
        return back()->with('status', 'Billing profile saved.');
    }

    public function invoice(Request $request, AdvertiserInvoice $advertiserInvoice, AdvertiserInvoiceService $invoices)
    {
        abort_unless($advertiserInvoice->organization_id === $request->user()->organization_id, 403);
        return $invoices->download($advertiserInvoice);
    }

    private function form(Request $request, Campaign $campaign): View
    {
        $this->advertiser($request);
        $sites = Site::withoutGlobalScopes()->with(['publisher', 'placements'])->where('status', 'APPROVED')->orderBy('display_name')->get();
        return view('advertiser.campaigns.form', [
            'campaign' => $campaign,
            'sites' => $sites,
            'placements' => Placement::withoutGlobalScopes()->whereIn('site_id', $sites->pluck('id'))->where('status', 'ACTIVE')->orderBy('name')->get(),
        ]);
    }

    private function validatedCampaign(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'objective' => ['required', 'string', 'max:80'],
            'pricing_model' => ['required', Rule::enum(CampaignPricingModel::class)],
            'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'],
            'currency' => ['required', 'string', 'size:3'], 'total_budget' => ['required', 'numeric', 'min:0'],
            'daily_budget' => ['nullable', 'numeric', 'min:0'], 'unit_price' => ['nullable', 'numeric', 'min:0'],
            'impression_goal' => ['nullable', 'integer', 'min:0'], 'click_goal' => ['nullable', 'integer', 'min:0'],
            'frequency_cap_impressions' => ['nullable', 'integer', 'between:1,1000'], 'frequency_cap_days' => ['nullable', 'integer', 'between:1,90'],
            'landing_url' => ['nullable', 'url:http,https', 'max:2048'], 'advertiser_notes' => ['nullable', 'string', 'max:10000'],
            'countries' => ['nullable', 'array'], 'countries.*' => ['string', 'size:2'],
            'devices' => ['nullable', 'array'], 'devices.*' => ['string', Rule::in(['DESKTOP', 'TABLET', 'MOBILE', 'CONNECTED_TV'])],
            'site_ids' => ['required', 'array', 'min:1'], 'site_ids.*' => ['ulid', 'exists:sites,id'],
            'placement_ids' => ['nullable', 'array'], 'placement_ids.*' => ['ulid', 'exists:placements,id'],
        ]);
        $data['currency'] = strtoupper($data['currency']);
        $data['total_budget_minor'] = (int) round(((float) $data['total_budget']) * 100);
        $data['daily_budget_minor'] = filled($data['daily_budget'] ?? null) ? (int) round(((float) $data['daily_budget']) * 100) : null;
        $data['unit_price_minor'] = filled($data['unit_price'] ?? null) ? (int) round(((float) $data['unit_price']) * 100) : 0;
        unset($data['total_budget'], $data['daily_budget'], $data['unit_price']);
        return $data;
    }

    private function validatedCreative(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'type' => ['required', Rule::enum(CampaignCreativeType::class)],
            'creative_file' => ['nullable', 'file'], 'width' => ['nullable', 'integer', 'between:1,10000'], 'height' => ['nullable', 'integer', 'between:1,10000'],
            'landing_url' => ['nullable', 'url:http,https', 'max:2048'], 'click_through_url' => ['nullable', 'url:http,https', 'max:2048'],
            'html_content' => ['nullable', 'string', 'max:1000000'], 'vast_url' => ['nullable', 'url:http,https', 'max:2048'],
            'native_assets_json' => ['nullable', 'string', 'max:100000'], 'text_content' => ['nullable', 'string', 'max:10000'],
        ]);
        try {
            $data['native_assets'] = filled($data['native_assets_json'] ?? null) ? json_decode($data['native_assets_json'], true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            abort(422, 'Native assets must be valid JSON.');
        }
        unset($data['native_assets_json']);
        return $data;
    }

    private function advertiser(Request $request): Advertiser
    {
        return Advertiser::withoutGlobalScopes()->where('organization_id', $request->user()->organization_id)->firstOrFail();
    }

    private function owns(Request $request, Campaign $campaign): void
    {
        abort_unless($campaign->organization_id === $request->user()->organization_id, 403);
    }
}
