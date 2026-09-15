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

        $referrals = Publisher::withoutGlobalScopes()
            ->where('referred_by_publisher_id', $publisher->id)
            ->orderByDesc('referred_at')
            ->orderBy('display_name')
            ->paginate(25, ['id', 'display_name', 'status', 'referred_at'], 'referrals_page');

        $commissions = PublisherAffiliateCommission::query()
            ->with(['referredPublisher', 'period'])
            ->where('referrer_publisher_id', $publisher->id)
            ->latest('created_at')
            ->paginate(25, ['*'], 'commissions_page');

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
            'referrals' => $referrals,
            'earnedByCurrency' => $earnedByCurrency,
            'commissions' => $commissions,
        ]);
    }
}
