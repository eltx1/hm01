<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BidderAdsTxtRequirement;
use App\Enums\SupplyChainReviewStatus;
use App\Http\Controllers\Controller;
use App\Models\BidderAccount;
use App\Models\BidderAdsTxtRecord;
use App\Models\BidderSiteMapping;
use App\Models\Site;
use App\Services\Compliance\AdsTxtComplianceService;
use App\Services\Prebid\BidderAdsTxtService;
use App\Services\Prebid\BidderSellersJsonVerifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BidderAdsTxtController extends Controller
{
    public function index(Site $site, AdsTxtComplianceService $compliance, BidderAdsTxtService $records): View
    {
        $mappings = BidderSiteMapping::withoutGlobalScopes()
            ->with(['account.bidder', 'account.siteMappings.site', 'account.adsTxtRecords.site'])
            ->where('site_id', $site->id)->orderBy('sequence')->get();

        return view('admin.prebid.ads-txt', [
            'site' => $site,
            'mappings' => $mappings,
            'accounts' => $mappings->pluck('account')->filter()->unique('id')->values(),
            'canonical' => $compliance->canonical($site),
            'readiness' => $records->readinessForSite($site),
            'requirements' => BidderAdsTxtRequirement::cases(),
        ]);
    }

    public function requirement(Request $request, Site $site, BidderAccount $bidderAccount, BidderAdsTxtService $records): RedirectResponse
    {
        $this->assertMapped($site, $bidderAccount);
        $data = $request->validate([
            'ads_txt_requirement' => ['required', Rule::enum(BidderAdsTxtRequirement::class)],
            'ads_txt_evidence_url' => ['nullable', 'url:https', 'max:1000'],
            'ads_txt_requirement_verified_at' => ['nullable', 'date', 'before_or_equal:today'],
        ]);
        $records->updateRequirement($bidderAccount, $data, $request->user());

        return back()->with('status', 'Bidder ads.txt requirement evidence updated.');
    }

    public function store(Request $request, Site $site, BidderAccount $bidderAccount, BidderAdsTxtService $records): RedirectResponse
    {
        $this->assertMapped($site, $bidderAccount);
        $data = $this->validatedRecord($request);
        $scopeSite = $data['scope'] === 'SITE' ? $site : null;
        $records->create($bidderAccount, $scopeSite, $data, $request->user());

        return back()->with('status', 'Bidder authorized-seller record created and awaiting review.');
    }

    public function update(Request $request, Site $site, BidderAdsTxtRecord $bidderAdsTxtRecord, BidderAdsTxtService $records): RedirectResponse
    {
        $account = BidderAccount::withoutGlobalScopes()->findOrFail($bidderAdsTxtRecord->bidder_account_id);
        $this->assertMapped($site, $account);
        $data = $this->validatedRecord($request);
        $scopeSite = $data['scope'] === 'SITE' ? $site : null;
        $records->update($bidderAdsTxtRecord, $scopeSite, $data, $request->user());

        return back()->with('status', 'Bidder authorized-seller record updated and review reopened.');
    }

    public function disable(Request $request, Site $site, BidderAdsTxtRecord $bidderAdsTxtRecord, BidderAdsTxtService $records): RedirectResponse
    {
        $this->assertRecordMapped($site, $bidderAdsTxtRecord);
        $records->disable($bidderAdsTxtRecord, $request->user());

        return back()->with('status', 'Bidder authorized-seller record disabled.');
    }

    public function review(Request $request, Site $site, BidderAdsTxtRecord $bidderAdsTxtRecord, BidderAdsTxtService $records): RedirectResponse
    {
        $this->assertRecordMapped($site, $bidderAdsTxtRecord);
        $data = $request->validate(['review_status' => ['required', Rule::enum(SupplyChainReviewStatus::class)]]);
        $records->review($bidderAdsTxtRecord, SupplyChainReviewStatus::from($data['review_status']), $request->user());

        return back()->with('status', 'Bidder authorized-seller review recorded.');
    }

    public function verifyRemote(Request $request, Site $site, BidderAdsTxtRecord $bidderAdsTxtRecord, BidderSellersJsonVerifier $verifier): RedirectResponse
    {
        $this->assertRecordMapped($site, $bidderAdsTxtRecord);
        $record = $verifier->verify($bidderAdsTxtRecord, $request->user());

        return back()->with('status', 'Remote sellers.json verification: '.$record->remote_verification_status->value.'.');
    }

    private function validatedRecord(Request $request): array
    {
        return $request->validate([
            'scope' => ['required', Rule::in(['GLOBAL', 'SITE'])],
            'advertising_system_domain' => ['required', 'string', 'max:255'],
            'publisher_account_id' => ['required', 'string', 'max:255'],
            'relationship' => ['required', Rule::in(['DIRECT', 'RESELLER'])],
            'certification_authority_id' => ['nullable', 'string', 'max:128'],
        ]);
    }

    private function assertMapped(Site $site, BidderAccount $account): void
    {
        abort_unless(BidderSiteMapping::withoutGlobalScopes()
            ->where('site_id', $site->id)->where('bidder_account_id', $account->id)->exists(), 404);
    }

    private function assertRecordMapped(Site $site, BidderAdsTxtRecord $record): void
    {
        $this->assertMapped($site, BidderAccount::withoutGlobalScopes()->findOrFail($record->bidder_account_id));
        abort_unless($record->site_id === null || $record->site_id === $site->id, 404);
    }
}
