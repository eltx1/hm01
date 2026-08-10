<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Monetization\SiteMonetizationReadinessService;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class MonetizationController extends Controller
{
    public function index(Request $request, SiteMonetizationReadinessService $readiness): View
    {
        $sites = Site::query()->orderBy('display_name')->paginate(20);
        $health = $sites->getCollection()->mapWithKeys(
            fn (Site $site): array => [$site->id => $readiness->publisher($site)]
        );

        return view('publisher.monetization.index', [
            'sites' => $sites,
            'health' => $health,
        ]);
    }
}
