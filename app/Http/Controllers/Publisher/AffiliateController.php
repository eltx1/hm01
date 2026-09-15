<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\Publisher;
use App\Models\PublisherAffiliateCommission;
use App\Services\Reporting\PublisherAffiliateService;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class AffiliateController extends Controller
{
    public function index(Request $request, PublisherAffiliateService $affiliates): View
    {
        $publisher = Publisher::withoutGlobalScopes()
            ->where('organization_id', $request->user()->organization_id)
            ->firstOrFail();

        $commissions = PublisherAffiliateCommission::query()
            ->with(['referredPublisher', 'period'])
            ->where('referrer_publisher_id', $publisher->id)
            ->latest('created_at')
            ->paginate(25);

        $earnedByCurrency = PublisherAffiliateCommission::query()
            ->selectRaw('currency, SUM(commission_minor) AS total_minor')
            ->where('referrer_publisher_id', $publisher->id)
            ->where('status', 'EARNED')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get();

        return view('publisher.affiliate.index', [
            'publisher' => $publisher,
            'referralUrl' => $affiliates->referralUrl($publisher),
            'effectiveRateBp' => $affiliates->effectiveRateBp($publisher),
            'referralsCount' => $publisher->referrals()->count(),
            'earnedByCurrency' => $earnedByCurrency,
            'commissions' => $commissions,
        ]);
    }
}
