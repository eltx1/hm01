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

        return view('publisher.affiliate.index', [
            'publisher' => $publisher,
            'referralUrl' => $affiliates->referralUrl($publisher),
            'effectiveRateBp' => $affiliates->effectiveRateBp($publisher),
            'referralsCount' => $publisher->referrals()->count(),
            'earnedMinor' => (int) $publisher->affiliateCommissions()->where('status', 'EARNED')->sum('commission_minor'),
            'commissions' => $commissions,
        ]);
    }
}
