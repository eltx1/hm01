<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BidderAccount;
use App\Models\Site;
use App\Services\Audit\AuditRecorder;
use App\Services\Compliance\SupplyChainControlCenterService;
use App\Services\SupplyChain\SupplyChainPublicOriginVerifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class SupplyChainControlCenterController extends Controller
{
    public function overview(SupplyChainControlCenterService $center): View
    {
        return $this->render('overview', $center);
    }

    public function section(string $section, SupplyChainControlCenterService $center): View
    {
        return $this->render($section, $center);
    }

    public function site(Site $site, SupplyChainControlCenterService $center): View
    {
        $site->loadMissing('publisher');

        return view('admin.compliance.supply-chain.site', [
            'detail' => $center->site($site),
            'tabs' => $this->tabs(),
        ]);
    }

    public function bidder(BidderAccount $bidderAccount, SupplyChainControlCenterService $center): View
    {
        return view('admin.compliance.supply-chain.bidder', [
            'detail' => $center->bidder($bidderAccount),
            'tabs' => $this->tabs(),
        ]);
    }

    public function verifySellersJson(
        Request $request,
        SupplyChainPublicOriginVerifier $verifier,
        AuditRecorder $audit,
    ): RedirectResponse {
        $result = $verifier->verifySellersJson();
        $audit->record(
            'supply_chain.sellers_json.public_origin.verified',
            $request->user()->organization_id,
            $request->user(),
            newValues: [
                'verified' => $result['verified'],
                'code' => $result['code'],
                'canonical_url' => $result['canonical_url'],
                'final_url' => $result['final_url'],
                'http_status' => $result['http_status'],
                'payload_sha256' => $result['payload_sha256'],
            ],
        );

        return back()->with(
            $result['verified'] ? 'status' : 'error',
            $result['verified']
                ? 'Canonical sellers.json public origin verified against the current generated payload.'
                : 'Canonical sellers.json verification failed: '.$result['code'].'.',
        );
    }

    private function render(string $section, SupplyChainControlCenterService $center): View
    {
        return view('admin.compliance.supply-chain.index', [
            'section' => $section,
            'data' => $center->section($section),
            'tabs' => $this->tabs(),
        ]);
    }

    /** @return list<array{key:string,label:string,href:string}> */
    private function tabs(): array
    {
        $labels = [
            'overview' => 'Overview',
            'master-ads-txt' => 'Master Ads.txt',
            'horus-sellers' => 'Horus Sellers',
            'bidder-authorizations' => 'Bidder Authorizations',
            'direct-demand-authorizations' => 'Direct Demand Authorizations',
            'websites' => 'Websites',
            'sellers-json' => 'sellers.json',
            'findings' => 'Verification / Findings',
        ];

        return collect($labels)->map(fn (string $label, string $key): array => [
            'key' => $key,
            'label' => $label,
            'href' => $key === 'overview'
                ? route('admin.compliance.supply-chain.overview')
                : route('admin.compliance.supply-chain.section', $key),
        ])->values()->all();
    }
}
